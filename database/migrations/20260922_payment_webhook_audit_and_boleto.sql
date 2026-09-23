-- Cobrança Fácil: dados de boleto e auditoria de webhooks.
-- Execute uma única vez em instalações já existentes, após fazer backup.

ALTER TABLE installments
  ADD COLUMN boleto_digitable_line VARCHAR(255) NULL AFTER payment_url,
  ADD COLUMN boleto_pdf_url TEXT NULL AFTER boleto_digitable_line;

CREATE TABLE payment_webhook_events (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  provider VARCHAR(32) NOT NULL,
  event_type VARCHAR(120) NULL,
  external_id VARCHAR(255) NULL,
  payload LONGTEXT NOT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  result_message TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  KEY idx_payment_webhook_events_company_created (company_id, created_at),
  KEY idx_payment_webhook_events_external (provider, external_id),
  CONSTRAINT fk_payment_webhook_events_company
    FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_delivery_log (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  installment_id CHAR(36) NOT NULL,
  channel VARCHAR(16) NOT NULL,
  trigger_type VARCHAR(64) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  detail TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  KEY idx_message_delivery_company_created (company_id, created_at),
  KEY idx_message_delivery_installment (installment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
