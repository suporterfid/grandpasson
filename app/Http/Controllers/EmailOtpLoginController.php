<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpVerifyResult;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;
use GrandpaSSOn\Support\RpRequestValidator;

/**
 * Sign in via a one-time code emailed to the user (R17) — a fourth login
 * option alongside the OAuth providers. Unlike LoginController, every
 * user-facing failure here renders a themed HTML page rather than JSON,
 * since this whole flow is reached only via browser navigation and forms.
 */
final class EmailOtpLoginController
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

        $this->renderEmailForm(
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
        if (!RateLimitGate::allowEmailOtpStart($pdo, 'email_otp_start', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        // Never trust the hidden form fields alone: re-validate server-side,
        // before minting a code or sending mail.
        $validated = RpRequestValidator::validate($pdo, $_POST);
        if (!$validated->ok) {
            $this->fail($config, $validated->status, (string) $validated->message);

            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->renderEmailForm(
                $config,
                (string) $validated->clientId,
                (string) $validated->redirectUri,
                (string) $validated->clientState,
                $validated->returnTo,
                $validated->codeChallenge,
                $validated->codeChallengeMethod,
                'Enter a valid email address.',
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

        // A null code means the per-email pending cap was hit; still render
        // the same confirmation so this can't be used to enumerate accounts.
        if ($started['code'] !== null) {
            $brokerName = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
            $minutes = (int) ceil(((int) $emailOtp['ttl_seconds']) / 60);
            (new MailerFactory($config['mail']))->make()->send(
                $email,
                $brokerName . ' sign-in code',
                "Your {$brokerName} sign-in code is {$started['code']}.\n\n"
                . "This code expires in {$minutes} minute(s).\n"
                . "If you didn't request this, you can safely ignore this email.\n",
            );
        }

        $_SESSION['email_otp_id'] = $started['id'];
        $this->renderCheckEmail($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verifyForm(array $config, array $params = []): void
    {
        $id = $_SESSION['email_otp_id'] ?? null;
        if (!is_string($id) || $id === '') {
            Http::redirect(Html::basePath($config) . '/login/email');

            return;
        }

        $this->renderCodeForm($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verify(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $id = $_SESSION['email_otp_id'] ?? null;
        if (!is_string($id) || $id === '') {
            Http::redirect(Html::basePath($config) . '/login/email');

            return;
        }

        if (!RateLimitGate::allowEmailOtpVerify($pdo, 'email_otp_verify', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $audit = new AuditLogger($pdo);
        $submittedCode = trim((string) ($_POST['code'] ?? ''));
        $result = (new EmailOtpService($pdo))->verify($id, $submittedCode);

        if ($result->status === EmailOtpVerifyResult::WRONG_CODE) {
            $this->renderCodeForm($config, "Incorrect code. {$result->attemptsRemaining} attempt(s) remaining.");

            return;
        }

        if ($result->status !== EmailOtpVerifyResult::OK) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 400, 'That code is no longer valid. Please request a new one.');

            return;
        }

        try {
            $identity = new NormalizedIdentity(
                provider: 'email_otp',
                subject: (string) $result->email,
                email: (string) $result->email,
                emailVerified: true,
                name: null,
            );
            $provisioner = new UserProvisioner($pdo);
            $user = $provisioner->resolve($identity);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user->id;
            $_SESSION['email'] = $user->primaryEmail;
            $_SESSION['display_name'] = $user->displayName;
            $_SESSION['status'] = $user->status;
            $_SESSION['avatar_url'] = $user->avatarUrl;
            Csrf::token();

            $rawCode = (new AuthCodeService($pdo))->mint(
                $user->id,
                (string) $result->clientId,
                (string) $result->redirectUri,
                $result->codeChallenge,
                $result->codeChallengeMethod,
            );

            $redirectUri = (string) $result->redirectUri;
            $clientState = (string) $result->clientState;
            unset($_SESSION['email_otp_id']);

            $audit->log('login.success', $user->id, 'email_otp', Http::clientIp());

            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            Http::redirect($redirectUri . $sep . http_build_query([
                'code' => $rawCode,
                'state' => $clientState,
            ]));
        } catch (AccountPendingException $e) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 403, 'Your account is awaiting admin approval.');
        } catch (AccountRejectedException $e) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 403, 'Your signup was not approved.');
        } catch (ProviderException $e) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 400, 'Sign-in failed. Please try again.');
        }
    }

    /** @param array<string, mixed> $config */
    private function renderEmailForm(
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
        $action = Html::e(Html::basePath($config) . '/login/email/start');

        echo Html::pageStart($config, $name . ' - Sign in with email');
        echo '<div class="prose">';
        echo '<h1>Sign in with email</h1>';
        echo '<p class="lead">We will email you a one-time code to sign in.</p>';
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
        echo '<label for="email">Email address</label>';
        echo '<input type="email" id="email" name="email" required autofocus autocomplete="email">';
        echo '<p><button type="submit" class="btn btn--primary">Send code</button></p>';
        echo '</form>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderCheckEmail(array $config): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $verifyHref = Html::e(Html::basePath($config) . '/login/email/verify');

        echo Html::pageStart($config, $name . ' - Check your email');
        echo '<div class="prose">';
        echo '<div class="card">';
        echo '<h1>Check your email</h1>';
        echo '<p>If that address has an account, we sent it a sign-in code.</p>';
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
        $action = Html::e(Html::basePath($config) . '/login/email/verify');
        $restartHref = Html::e(Html::basePath($config) . '/login/email');

        echo Html::pageStart($config, $name . ' - Enter your code');
        echo '<div class="prose">';
        echo '<h1>Enter your code</h1>';
        echo '<p class="lead">Enter the code we emailed you.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<label for="code">Sign-in code</label>';
        echo '<input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code">';
        echo '<p><button type="submit" class="btn btn--primary">Verify</button></p>';
        echo '</form>';
        echo '<p class="text-small"><a href="' . $restartHref . '">Request a new code</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function fail(array $config, int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo Html::pageStart($config, 'Sign-in failed');
        echo '<div class="prose">';
        echo '<h1>Sign-in failed</h1>';
        echo '<p>' . Html::e($message) . '</p>';
        $href = Html::e(Html::basePath($config) . '/login/email');
        echo '<p><a class="btn btn--primary" href="' . $href . '">Try again</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }
}
