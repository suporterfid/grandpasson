<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpVerifyResult;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;
use GrandpaSSOn\Support\RpRequestValidator;

/**
 * Self-enrollment (design doc: docs/superpowers/specs/2026-08-06-self-enrollment-admin-approval-design.md).
 * Email OTP entry point lives here (form/start/verifyForm/verify); the
 * OAuth-completion entry point (complete/completeSubmit) is added in a
 * follow-up task, reached from CallbackController when
 * UserProvisioner::resolve() throws AccountNotFoundException.
 */
final class SignupController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function form(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $validated = RpRequestValidator::validate($pdo, $_GET);
        if (!$validated->ok) {
            $this->fail($config, $validated->status, (string) $validated->message);

            return;
        }

        $this->renderForm(
            $config,
            (string) $validated->clientId,
            (string) $validated->redirectUri,
            (string) $validated->clientState,
            $validated->returnTo,
            $validated->codeChallenge,
            $validated->codeChallengeMethod,
        );
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function start(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowEmailOtpStart($pdo, 'signup_email_start', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $validated = RpRequestValidator::validate($pdo, $_POST);
        if (!$validated->ok) {
            $this->fail($config, $validated->status, (string) $validated->message);

            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $justification = trim((string) ($_POST['justification'] ?? ''));

        $error = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Enter a valid email address.';
        } elseif ($name === '') {
            $error = 'Enter your name.';
        } elseif ($justification === '') {
            $error = 'Tell us why you need access.';
        }

        if ($error === null) {
            try {
                $this->signupService($pdo, $config)->assertEmailAllowed(strtolower($email));
            } catch (ProviderException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null) {
            $this->renderForm(
                $config,
                (string) $validated->clientId,
                (string) $validated->redirectUri,
                (string) $validated->clientState,
                $validated->returnTo,
                $validated->codeChallenge,
                $validated->codeChallengeMethod,
                $error,
            );

            return;
        }

        $emailOtp = $config['email_otp'];
        $started = (new EmailOtpService($pdo))->start(
            $email,
            [
                'client_id' => (string) $validated->clientId,
                'redirect_uri' => (string) $validated->redirectUri,
                'client_state' => (string) $validated->clientState,
                'return_to' => $validated->returnTo,
                'code_challenge' => $validated->codeChallenge,
                'code_challenge_method' => $validated->codeChallengeMethod,
            ],
            (int) $emailOtp['ttl_seconds'],
            (int) $emailOtp['max_attempts'],
            (int) $emailOtp['code_length'],
        );

        if ($started['code'] !== null) {
            $brokerName = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
            $minutes = (int) ceil(((int) $emailOtp['ttl_seconds']) / 60);
            (new MailerFactory($config['mail']))->make()->send(
                $email,
                $brokerName . ' signup code',
                "Your {$brokerName} signup verification code is {$started['code']}.\n\n"
                . "This code expires in {$minutes} minute(s).\n"
                . "If you didn't request this, you can safely ignore this email.\n",
            );
        }

        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => $name, 'justification' => $justification];
        $this->renderCheckEmail($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verifyForm(array $config, array $params = []): void
    {
        $id = $_SESSION['signup_otp_id'] ?? null;
        if (!is_string($id) || $id === '') {
            Http::redirect(Html::basePath($config) . '/signup');

            return;
        }

        $this->renderCodeForm($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verify(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $id = $_SESSION['signup_otp_id'] ?? null;
        $profile = $_SESSION['signup_profile'] ?? null;
        if (!is_string($id) || $id === '' || !is_array($profile)) {
            Http::redirect(Html::basePath($config) . '/signup');

            return;
        }

        if (!RateLimitGate::allowEmailOtpVerify($pdo, 'signup_email_verify', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $submittedCode = trim((string) ($_POST['code'] ?? ''));
        $result = (new EmailOtpService($pdo))->verify($id, $submittedCode);

        if ($result->status === EmailOtpVerifyResult::WRONG_CODE) {
            $this->renderCodeForm($config, "Incorrect code. {$result->attemptsRemaining} attempt(s) remaining.");

            return;
        }

        if ($result->status !== EmailOtpVerifyResult::OK) {
            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->fail($config, 400, 'That code is no longer valid. Please start again.');

            return;
        }

        try {
            $identity = new NormalizedIdentity(
                provider: 'email_otp',
                subject: (string) $result->email,
                email: (string) $result->email,
                emailVerified: true,
                name: (string) $profile['name'],
            );
            $this->signupService($pdo, $config)->createPending(
                $identity,
                (string) $profile['name'],
                (string) $profile['justification'],
                'email',
            );

            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->renderPending($config);
        } catch (ProviderException $e) {
            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->fail($config, 400, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function complete(array $config, array $params = []): void
    {
        $pending = $_SESSION['pending_signup'] ?? null;
        if (!is_array($pending)) {
            Http::redirect(Html::basePath($config) . '/login');

            return;
        }

        $this->renderComplete($config, (string) ($pending['name'] ?? ''), (string) $pending['email']);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function completeSubmit(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $pending = $_SESSION['pending_signup'] ?? null;
        if (!is_array($pending)) {
            Http::redirect(Html::basePath($config) . '/login');

            return;
        }

        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $name = trim((string) ($_POST['name'] ?? (string) ($pending['name'] ?? '')));
        $justification = trim((string) ($_POST['justification'] ?? ''));

        if ($name === '' || $justification === '') {
            $this->renderComplete($config, $name, (string) $pending['email'], 'Name and justification are required.');

            return;
        }

        try {
            $identity = new NormalizedIdentity(
                provider: (string) $pending['provider'],
                subject: (string) $pending['subject'],
                email: (string) $pending['email'],
                emailVerified: true,
                name: $name,
                avatarUrl: $pending['avatar_url'] ?? null,
                username: $pending['username'] ?? null,
                rawClaims: is_array($pending['raw_claims'] ?? null) ? $pending['raw_claims'] : [],
            );
            $this->signupService($pdo, $config)->createPending($identity, $name, $justification, (string) $pending['provider']);

            unset($_SESSION['pending_signup'], $_SESSION['oauth']);
            $this->renderPending($config);
        } catch (ProviderException $e) {
            $this->renderComplete($config, $name, (string) $pending['email'], $e->getMessage());
        }
    }

    /** @param array<string, mixed> $config */
    private function renderComplete(array $config, string $name, string $email, ?string $error = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $brokerName = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/complete');

        echo Html::pageStart($config, $brokerName . ' - Request access');
        echo '<div class="prose">';
        echo '<h1>Request access</h1>';
        echo '<p class="lead">Your email is verified. Tell us why you need access — an admin will review your request.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<label for="name">Your name</label>';
        echo '<input type="text" id="name" name="name" value="' . Html::e($name) . '" required>';
        echo '<label>Email address</label>';
        echo '<input type="email" value="' . Html::e($email) . '" readonly disabled>';
        echo '<label for="justification">Why do you need access?</label>';
        echo '<textarea id="justification" name="justification" rows="3" required></textarea>';
        echo '<p><button type="submit" class="btn btn--primary">Request access</button></p>';
        echo '</form>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function signupService(\PDO $pdo, array $config): SignupService
    {
        return new SignupService($pdo, [
            'app_env' => (string) $config['app_env'],
            'allowed_email_domains' => $config['allowed_email_domains'] ?? [],
            'mail' => $config['mail'],
            'broker' => $config['broker'],
            'admin_notification_emails' => $config['admin_notification_emails'] ?? [],
        ]);
    }

    /** @param array<string, mixed> $config */
    private function renderForm(
        array $config,
        string $clientId,
        string $redirectUri,
        string $clientState,
        ?string $returnTo,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $error = null,
    ): void {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/start');

        echo Html::pageStart($config, $name . ' - Request access');
        echo '<div class="prose">';
        echo '<h1>Request access</h1>';
        echo '<p class="lead">Tell us who you are — an admin will review your request.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<input type="hidden" name="client_id" value="' . Html::e($clientId) . '">';
        echo '<input type="hidden" name="redirect_uri" value="' . Html::e($redirectUri) . '">';
        echo '<input type="hidden" name="state" value="' . Html::e($clientState) . '">';
        if ($returnTo !== null) {
            echo '<input type="hidden" name="return_to" value="' . Html::e($returnTo) . '">';
        }
        if ($codeChallenge !== null) {
            echo '<input type="hidden" name="code_challenge" value="' . Html::e($codeChallenge) . '">';
        }
        if ($codeChallengeMethod !== null) {
            echo '<input type="hidden" name="code_challenge_method" value="' . Html::e($codeChallengeMethod) . '">';
        }
        echo '<label for="name">Your name</label>';
        echo '<input type="text" id="name" name="name" required autofocus autocomplete="name">';
        echo '<label for="email">Email address</label>';
        echo '<input type="email" id="email" name="email" required autocomplete="email">';
        echo '<label for="justification">Why do you need access?</label>';
        echo '<textarea id="justification" name="justification" rows="3" required></textarea>';
        echo '<p><button type="submit" class="btn btn--primary">Request access</button></p>';
        echo '</form>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderCheckEmail(array $config): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $verifyHref = Html::e(Html::basePath($config) . '/signup/verify');

        echo Html::pageStart($config, $name . ' - Check your email');
        echo '<div class="prose">';
        echo '<div class="card">';
        echo '<h1>Check your email</h1>';
        echo '<p>If that address is eligible, we sent it a verification code.</p>';
        echo '<p><a class="btn btn--primary" href="' . $verifyHref . '">Enter code</a></p>';
        echo '</div>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderCodeForm(array $config, ?string $error = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/verify');
        $restartHref = Html::e(Html::basePath($config) . '/signup');

        echo Html::pageStart($config, $name . ' - Enter your code');
        echo '<div class="prose">';
        echo '<h1>Enter your code</h1>';
        echo '<p class="lead">Enter the verification code we emailed you.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<label for="code">Verification code</label>';
        echo '<input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code">';
        echo '<p><button type="submit" class="btn btn--primary">Verify</button></p>';
        echo '</form>';
        echo '<p class="text-small"><a href="' . $restartHref . '">Start over</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderPending(array $config): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');

        echo Html::pageStart($config, $name . ' - Awaiting approval');
        echo '<div class="prose">';
        echo '<div class="card">';
        echo '<h1>Request received</h1>';
        echo '<p>An admin will review your request. You will be able to sign in once approved.</p>';
        echo '</div>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function fail(array $config, int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo Html::pageStart($config, 'Request failed');
        echo '<div class="prose">';
        echo '<h1>Request failed</h1>';
        echo '<p>' . Html::e($message) . '</p>';
        $href = Html::e(Html::basePath($config) . '/signup');
        echo '<p><a class="btn btn--primary" href="' . $href . '">Try again</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }
}
