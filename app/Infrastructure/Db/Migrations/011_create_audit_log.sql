CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('subject','service','admin','system') NOT NULL,
  actor_id VARCHAR(100) NULL,
  action VARCHAR(100) NOT NULL,
  target VARCHAR(255) NULL,
  client_id VARCHAR(100) NULL,
  ip_hash VARCHAR(64) NULL,
  user_agent VARCHAR(512) NULL,
  result ENUM('success','failure') NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_log_created (created_at),
  KEY idx_audit_log_action (action)
) ENGINE=InnoDB;
