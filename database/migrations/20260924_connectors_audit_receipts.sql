ALTER TABLE installments ADD COLUMN receipt_url TEXT NULL AFTER boleto_pdf_url;
CREATE TABLE charge_audit_log (
 id CHAR(36) PRIMARY KEY, company_id CHAR(36) NOT NULL, charge_id CHAR(36) NOT NULL, action VARCHAR(64) NOT NULL,
 before_data LONGTEXT NULL, after_data LONGTEXT NULL, metadata LONGTEXT NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 KEY idx_charge_audit_charge (charge_id,created_at), KEY idx_charge_audit_company (company_id,created_at),
 CONSTRAINT fk_charge_audit_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT fk_charge_audit_charge FOREIGN KEY(charge_id) REFERENCES charges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
