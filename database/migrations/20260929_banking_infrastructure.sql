-- Infraestrutura extensível para conectores bancários. Retrocompatível com Asaas e Mercado Pago.
ALTER TABLE payment_accounts MODIFY provider VARCHAR(48) NOT NULL;
ALTER TABLE payment_accounts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE charges MODIFY payment_gateway VARCHAR(48) NOT NULL;
ALTER TABLE payment_accounts ADD COLUMN credentials_encrypted LONGTEXT NULL AFTER webhook_secret;
ALTER TABLE payment_accounts ADD COLUMN provider_config LONGTEXT NULL AFTER credentials_encrypted;
ALTER TABLE payment_accounts ADD COLUMN token_cache_encrypted LONGTEXT NULL AFTER provider_config;
ALTER TABLE payment_accounts ADD COLUMN token_expires_at DATETIME(3) NULL AFTER token_cache_encrypted;

ALTER TABLE installments ADD COLUMN provider_status VARCHAR(120) NULL AFTER status;
ALTER TABLE installments ADD COLUMN provider_event VARCHAR(120) NULL AFTER provider_status;
ALTER TABLE installments ADD COLUMN payment_origin VARCHAR(64) NULL AFTER provider_event;
ALTER TABLE installments ADD COLUMN txid VARCHAR(255) NULL AFTER external_id;
ALTER TABLE installments ADD COLUMN provider_reference VARCHAR(255) NULL AFTER txid;

CREATE TABLE payment_account_certificates (
  id CHAR(36) NOT NULL PRIMARY KEY,
  payment_account_id CHAR(36) NOT NULL,
  format VARCHAR(16) NOT NULL,
  certificate_encrypted LONGTEXT NOT NULL,
  private_key_encrypted LONGTEXT NULL,
  chain_encrypted LONGTEXT NULL,
  password_encrypted LONGTEXT NULL,
  fingerprint VARCHAR(128) NULL,
  valid_from DATETIME NULL,
  valid_until DATETIME NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_payment_account_certificate (payment_account_id),
  CONSTRAINT fk_payment_account_certificate FOREIGN KEY (payment_account_id) REFERENCES payment_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
