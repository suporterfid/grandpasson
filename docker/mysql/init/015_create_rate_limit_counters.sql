CREATE TABLE IF NOT EXISTS rate_limit_counters (
  counter_key CHAR(64) NOT NULL PRIMARY KEY,
  window_started_at INT UNSIGNED NOT NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;
