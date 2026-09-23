-- Cobx - importacao completa do banco
-- Gerado a partir de database/mysql_schema.sql
-- Uso: importe este arquivo em um banco MySQL/MariaDB vazio.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Arquivo: database/mysql_schema.sql
-- Cobx — schema MySQL (substitui Supabase/Postgres)
-- Crie o banco: CREATE DATABASE cobx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================
-- USERS (auth local)
-- =====================
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS subscription_payments;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS master_settings;
DROP TABLE IF EXISTS message_templates;
DROP TABLE IF EXISTS reminder_dispatch_log;
DROP TABLE IF EXISTS installments;
DROP TABLE IF EXISTS charges;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS client_categories;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS profiles;
DROP TABLE IF EXISTS plans;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plans (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  duration_months INT NOT NULL DEFAULT 1,
  charges_limit INT NOT NULL DEFAULT 999999999,
  users_limit INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  full_name VARCHAR(512) NULL,
  email VARCHAR(255) NULL,
  avatar_url VARCHAR(1024) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY profiles_user_id_unique (user_id),
  CONSTRAINT profiles_user_id_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  role ENUM('admin', 'company_owner', 'company_user') NOT NULL,
  UNIQUE KEY user_roles_user_role (user_id, role),
  CONSTRAINT user_roles_user_id_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
  id CHAR(36) NOT NULL PRIMARY KEY,
  owner_id CHAR(36) NOT NULL,
  plan_id CHAR(36) NULL,
  plan_renews_at DATE NULL,
  name VARCHAR(512) NOT NULL,
  cnpj VARCHAR(64) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(64) NULL,
  payment_gateway ENUM('mercadopago', 'asaas') NULL,
  gateway_api_key TEXT NULL,
  gateway_public_key TEXT NULL,
  gateway_environment VARCHAR(32) NULL DEFAULT 'sandbox',
  whatsapp_provider VARCHAR(64) NULL DEFAULT 'evolution',
  whatsapp_api_url TEXT NULL,
  whatsapp_token TEXT NULL,
  evolution_instance_name VARCHAR(128) NULL,
  send_payment_link TINYINT(1) NOT NULL DEFAULT 0,
  send_qrcode TINYINT(1) NOT NULL DEFAULT 1,
  send_copy_paste_key TINYINT(1) NOT NULL DEFAULT 1,
  billing_reminder_start_time TIME NOT NULL DEFAULT '08:00:00',
  billing_reminder_gap_seconds INT UNSIGNED NOT NULL DEFAULT 60,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  smtp_use_custom TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) NULL,
  smtp_port INT NULL DEFAULT 587,
  smtp_encryption VARCHAR(8) NOT NULL DEFAULT 'tls',
  smtp_username VARCHAR(255) NULL,
  smtp_password TEXT NULL,
  smtp_from_email VARCHAR(255) NULL,
  smtp_from_name VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT companies_owner_fk FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT companies_plan_fk FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_categories (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uk_company_category_name (company_id, name),
  CONSTRAINT fk_cc_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  category_id CHAR(36) NULL,
  name VARCHAR(512) NOT NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(64) NULL,
  document VARCHAR(64) NULL,
  external_id VARCHAR(255) NULL,
  address_street VARCHAR(255) NULL,
  address_number VARCHAR(32) NULL,
  address_complement VARCHAR(128) NULL,
  address_neighborhood VARCHAR(128) NULL,
  address_city VARCHAR(128) NULL,
  address_state VARCHAR(64) NULL,
  address_postal_code VARCHAR(32) NULL,
  address_country VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT clients_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_clients_category FOREIGN KEY (category_id) REFERENCES client_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  name VARCHAR(512) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL,
  installments_count INT NOT NULL DEFAULT 1,
  is_monthly TINYINT(1) NOT NULL DEFAULT 0,
  is_recurring_monthly TINYINT(1) NOT NULL DEFAULT 0,
  has_daily_interest TINYINT(1) NOT NULL DEFAULT 0,
  daily_interest_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE charges (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  client_id CHAR(36) NOT NULL,
  product_id CHAR(36) NULL,
  description TEXT NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  installments_count INT NOT NULL DEFAULT 1,
  payment_gateway ENUM('mercadopago', 'asaas') NOT NULL,
  status ENUM('pending', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
  external_id VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT charges_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT charges_client_fk FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_charges_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE installments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  charge_id CHAR(36) NOT NULL,
  company_id CHAR(36) NOT NULL,
  installment_number INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('pending', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
  paid_at DATETIME(3) NULL,
  external_id VARCHAR(255) NULL,
  payment_url TEXT NULL,
  boleto_digitable_line VARCHAR(255) NULL,
  boleto_pdf_url TEXT NULL,
  pix_qrcode TEXT NULL,
  pix_copy_paste TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT installments_charge_fk FOREIGN KEY (charge_id) REFERENCES charges (id) ON DELETE CASCADE,
  CONSTRAINT installments_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT fk_payment_webhook_events_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminder_dispatch_log (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  installment_id CHAR(36) NOT NULL,
  channel VARCHAR(16) NOT NULL,
  trigger_type VARCHAR(64) NOT NULL,
  sent_on DATE NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  UNIQUE KEY uq_reminder_day (installment_id, channel, trigger_type, sent_on),
  KEY idx_reminder_company_day (company_id, sent_on),
  CONSTRAINT fk_reminder_log_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_log_installment FOREIGN KEY (installment_id) REFERENCES installments (id) ON DELETE CASCADE
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

CREATE TABLE message_templates (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  channel VARCHAR(64) NOT NULL DEFAULT 'whatsapp',
  trigger_type VARCHAR(64) NOT NULL DEFAULT 'on_due_date',
  days_offset INT NOT NULL DEFAULT 0,
  subject VARCHAR(512) NULL,
  body TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  send_qrcode TINYINT(1) NOT NULL DEFAULT 0,
  send_copy_paste TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_message_templates_company_channel_trigger (company_id, channel, trigger_type),
  CONSTRAINT message_templates_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  KEY password_resets_token_hash (token_hash),
  KEY password_resets_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  company_id CHAR(36) NOT NULL,
  plan_id CHAR(36) NULL,
  status ENUM('trialing', 'active', 'past_due', 'cancelled') NOT NULL DEFAULT 'trialing',
  current_period_start DATE NULL,
  current_period_end DATE NULL,
  trial_ends_at DATE NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY subscriptions_company_unique (company_id),
  KEY subscriptions_plan_id (plan_id),
  KEY subscriptions_status_end (status, current_period_end),
  CONSTRAINT subscriptions_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT subscriptions_plan_fk FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_payments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  subscription_id CHAR(36) NULL,
  company_id CHAR(36) NOT NULL,
  plan_id CHAR(36) NULL,
  gateway VARCHAR(32) NOT NULL,
  external_id VARCHAR(255) NULL,
  external_reference VARCHAR(255) NULL,
  status VARCHAR(64) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_at DATETIME(3) NULL,
  raw_payload JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)),
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY subscription_payments_gateway_external (gateway, external_id),
  KEY subscription_payments_company (company_id),
  KEY subscription_payments_subscription (subscription_id),
  CONSTRAINT subscription_payments_subscription_fk FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL,
  CONSTRAINT subscription_payments_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT subscription_payments_plan_fk FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS master_settings (
  id TINYINT NOT NULL PRIMARY KEY,
  mercadopago_public_key TEXT NULL,
  mercadopago_access_token TEXT NULL,
  evolution_master_url TEXT NULL,
  evolution_master_api_key TEXT NULL,
  master_whatsapp_instance_name VARCHAR(120) NULL,
  notification_email VARCHAR(255) NULL,
  notification_phone VARCHAR(32) NULL,
  smtp_host VARCHAR(255) NULL,
  smtp_port INT NULL DEFAULT 587,
  smtp_encryption VARCHAR(8) NOT NULL DEFAULT 'tls',
  smtp_username VARCHAR(255) NULL,
  smtp_password TEXT NULL,
  smtp_from_email VARCHAR(255) NULL,
  smtp_from_name VARCHAR(255) NULL,
  cron_secret VARCHAR(255) NULL,
  cronjob_api_key VARCHAR(512) NULL,
  cronjob_job_id INT UNSIGNED NULL,
  cron_schedule_hour TINYINT UNSIGNED NOT NULL DEFAULT 8,
  cron_schedule_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
  brand_accent VARCHAR(32) NOT NULL DEFAULT 'amber',
  system_name VARCHAR(120) NOT NULL DEFAULT 'CobrançaFácil',
  updated_at DATETIME(3) NOT NULL DEFAULT (CURRENT_TIMESTAMP(3)) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO master_settings (id) VALUES (1);

-- Planos iniciais.
INSERT INTO plans (id, name, price, duration_months, charges_limit, users_limit, is_active) VALUES
  (UUID(), 'Starter', 49.90, 1, 999999999, 1, 1),
  (UUID(), 'Pro', 149.90, 2, 999999999, 1, 1);

-- O instalador web cria o super admin e uma empresa de teste automaticamente.

