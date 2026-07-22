CREATE TABLE IF NOT EXISTS tenant_members (
  tenant_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, user_id),
  KEY idx_tenant_members_user (user_id),
  CONSTRAINT fk_tenant_members_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
