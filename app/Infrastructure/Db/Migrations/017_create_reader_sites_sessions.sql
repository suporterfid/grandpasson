CREATE TABLE IF NOT EXISTS published_sites (
  site_id VARCHAR(100) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  visibility ENUM('public', 'authenticated', 'private') NOT NULL DEFAULT 'public',
  tenant_id CHAR(36) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  KEY idx_published_sites_tenant (tenant_id),
  CONSTRAINT fk_published_sites_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reader_sessions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  user_id CHAR(36) NOT NULL,
  site_id VARCHAR(100) NOT NULL,
  scopes TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_reader_sessions_hash (token_hash),
  KEY idx_reader_sessions_site (site_id),
  KEY idx_reader_sessions_expires (expires_at),
  CONSTRAINT fk_reader_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reader_sessions_site FOREIGN KEY (site_id) REFERENCES published_sites(site_id) ON DELETE CASCADE
) ENGINE=InnoDB;
