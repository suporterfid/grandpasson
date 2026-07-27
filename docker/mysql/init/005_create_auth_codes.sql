-- Raw auth codes are returned once in the redirect; only the hash is stored.
CREATE TABLE IF NOT EXISTS auth_codes (
  code_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  client_id VARCHAR(100) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
