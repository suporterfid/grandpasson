CREATE TABLE IF NOT EXISTS linked_identities (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  provider ENUM('google','microsoft','github') NOT NULL,
  provider_subject VARCHAR(255) NOT NULL,
  provider_email VARCHAR(255) NULL,
  provider_username VARCHAR(255) NULL,
  raw_claims_json TEXT NULL,
  linked_at DATETIME NOT NULL,
  last_login_at DATETIME NOT NULL,
  UNIQUE KEY uniq_provider_subject (provider, provider_subject),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
