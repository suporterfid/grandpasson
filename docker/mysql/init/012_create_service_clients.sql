CREATE TABLE IF NOT EXISTS service_clients (
  client_id VARCHAR(100) NOT NULL PRIMARY KEY,
  client_secret_hash VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  allowed_scopes TEXT NOT NULL,
  default_audience VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;
