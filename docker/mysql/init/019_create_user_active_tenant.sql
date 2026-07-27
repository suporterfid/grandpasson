CREATE TABLE IF NOT EXISTS user_active_tenant (
  user_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  KEY idx_user_active_tenant_tenant (tenant_id),
  CONSTRAINT fk_user_active_tenant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_active_tenant_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;
