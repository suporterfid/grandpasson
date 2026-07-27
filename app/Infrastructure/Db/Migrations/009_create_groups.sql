-- `groups` is a MySQL reserved word; always quote the table name.
CREATE TABLE IF NOT EXISTS `groups` (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_groups_tenant_slug (tenant_id, slug),
  KEY idx_groups_tenant (tenant_id),
  CONSTRAINT fk_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;
