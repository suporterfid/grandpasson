-- R17: allow email-OTP-sourced identities to be linked like any other provider.

ALTER TABLE linked_identities
  MODIFY COLUMN provider ENUM('google','microsoft','github','email_otp') NOT NULL;
