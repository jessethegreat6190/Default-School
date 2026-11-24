CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(100) NOT NULL,
  value_json JSON NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_settings_school_key (school_id, `key`),
  CONSTRAINT fk_settings_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NULL,
  provider VARCHAR(50) NOT NULL,
  event_type VARCHAR(100) NULL,
  external_ref VARCHAR(150) NULL,
  payload_json JSON NULL,
  headers_json JSON NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'received',
  error_message TEXT NULL,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wh_provider_ref (provider, external_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
