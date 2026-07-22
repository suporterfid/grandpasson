CREATE TABLE IF NOT EXISTS jwt_signing_keys (
  kid VARCHAR(64) NOT NULL PRIMARY KEY,
  alg VARCHAR(16) NOT NULL DEFAULT 'RS256',
  public_pem TEXT NOT NULL,
  private_pem TEXT NOT NULL,
  status ENUM('active', 'retiring', 'retired') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  retired_at DATETIME NULL,
  KEY idx_jwt_signing_keys_status (status)
) ENGINE=InnoDB;
