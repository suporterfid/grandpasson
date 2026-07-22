CREATE TABLE IF NOT EXISTS oauth_clients (
  client_id VARCHAR(100) NOT NULL PRIMARY KEY,
  client_secret_hash VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  redirect_uris TEXT NOT NULL,
  type ENUM('confidential','public') NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
