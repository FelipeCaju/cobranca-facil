ALTER TABLE payment_accounts ADD COLUMN webhook_secret TEXT NULL AFTER public_key;
ALTER TABLE payment_webhook_events ADD COLUMN event_key CHAR(64) NULL AFTER external_id;
ALTER TABLE installments ADD COLUMN receipt_url TEXT NULL AFTER boleto_pdf_url;
CREATE UNIQUE INDEX uq_webhook_event_key ON payment_webhook_events (company_id, provider, event_key);

CREATE TABLE integration_jobs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  job_type VARCHAR(64) NOT NULL,
  payload LONGTEXT NOT NULL,
  status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_at DATETIME(3) NULL,
  last_error TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY idx_jobs_ready (status, available_at),
  KEY idx_jobs_company (company_id, created_at),
  CONSTRAINT fk_jobs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE charge_audit_log (
 id CHAR(36) PRIMARY KEY, company_id CHAR(36) NOT NULL, charge_id CHAR(36) NOT NULL, action VARCHAR(64) NOT NULL,
 before_data LONGTEXT NULL, after_data LONGTEXT NULL, metadata LONGTEXT NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 KEY idx_charge_audit_charge (charge_id,created_at), KEY idx_charge_audit_company (company_id,created_at),
 CONSTRAINT fk_charge_audit_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT fk_charge_audit_charge FOREIGN KEY(charge_id) REFERENCES charges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
