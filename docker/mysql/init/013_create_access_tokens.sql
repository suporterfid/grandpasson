CREATE TABLE IF NOT EXISTS access_tokens (
  id CHAR(36) NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  client_id VARCHAR(100) NOT NULL,
  subject_user_id CHAR(36) NULL,
  scope TEXT NOT NULL,
  aud VARCHAR(255) NULL,
  tenant_id CHAR(36) NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  UNIQUE KEY uniq_access_tokens_hash (token_hash),
  KEY idx_access_tokens_client (client_id),
  KEY idx_access_tokens_expires (expires_at),
  CONSTRAINT fk_access_tokens_client FOREIGN KEY (client_id) REFERENCES service_clients(client_id) ON DELETE CASCADE
) ENGINE=InnoDB;
