-- R17: sign in via a one-time code emailed to the user. The pending RP
-- request params travel with the row so the session only carries an
-- opaque id between the "start" and "verify" HTTP steps.

CREATE TABLE IF NOT EXISTS email_otp_codes (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  client_id VARCHAR(100) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  client_state VARCHAR(255) NOT NULL,
  return_to VARCHAR(500) NULL,
  code_challenge VARCHAR(128) NULL,
  code_challenge_method VARCHAR(10) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_email_otp_codes_expires (expires_at),
  KEY idx_email_otp_codes_email (email)
) ENGINE=InnoDB;
