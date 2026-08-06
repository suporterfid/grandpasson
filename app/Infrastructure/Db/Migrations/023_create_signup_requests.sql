-- Audit trail for self-enrollment requests, 1:1 with the pending `users`
-- row created at signup time. Kept even after approve/reject for audit.
CREATE TABLE IF NOT EXISTS signup_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  justification TEXT NOT NULL,
  source ENUM('email','google','microsoft','github') NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by VARCHAR(255) NULL,
  reviewed_at DATETIME NULL,
  rejection_reason TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_signup_requests_user (user_id),
  KEY idx_signup_requests_status (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
