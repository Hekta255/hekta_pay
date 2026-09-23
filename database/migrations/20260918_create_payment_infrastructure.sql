-- WHAT: Creates the isolated Hekta Pay invoice, transaction, credential, gateway, and webhook tables.
-- WHY:  Payment records must be owned by Hekta Pay and remain independent from application databases.

CREATE TABLE IF NOT EXISTS hekta_app_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id VARCHAR(100) NOT NULL UNIQUE,
    app_secret_hash VARCHAR(255) NOT NULL,
    app_name VARCHAR(255) NOT NULL,
    owner_email VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    allowed_gateways JSON NULL,
    webhook_url VARCHAR(500) NULL,
    webhook_secret VARCHAR(255) NULL,
    rate_limit_per_minute INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_credentials_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_gateway_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(50) NOT NULL,
    environment VARCHAR(20) NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value_encrypted TEXT NOT NULL,
    ipn_url VARCHAR(500) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_gateway_environment_key (gateway, environment, config_key),
    INDEX idx_gateway_active (gateway, environment, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_invoices (
    id CHAR(36) PRIMARY KEY,
    app_id VARCHAR(100) NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    amount DECIMAL(12,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('pending','initiated','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    gateway VARCHAR(50) NOT NULL,
    environment VARCHAR(10) NOT NULL DEFAULT 'test',
    gateway_order_id VARCHAR(255) NULL,
    payment_url TEXT NULL,
    payment_method VARCHAR(100) NULL,
    metadata JSON NULL,
    invoice_type ENUM('subscription','renewal','upgrade','one_time') NOT NULL DEFAULT 'subscription',
    billing_period ENUM('monthly','yearly','quarterly','custom') NULL,
    subscription_term_months INT NOT NULL DEFAULT 1,
    due_date TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    webhook_sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_gateway_order (gateway, gateway_order_id),
    INDEX idx_invoice_app_tenant (app_id, tenant_id),
    INDEX idx_invoice_status (status),
    INDEX idx_invoice_created (created_at),
    CONSTRAINT fk_invoice_app FOREIGN KEY (app_id) REFERENCES hekta_app_credentials(app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_transactions (
    id CHAR(36) PRIMARY KEY,
    invoice_id CHAR(36) NOT NULL,
    app_id VARCHAR(100) NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    amount DECIMAL(12,4) NOT NULL,
    currency CHAR(3) NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    gateway_transaction_id VARCHAR(255) NOT NULL,
    payment_method VARCHAR(100) NOT NULL,
    metadata JSON NULL,
    transaction_data JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_transaction_gateway (gateway, gateway_transaction_id),
    INDEX idx_transaction_invoice (invoice_id),
    CONSTRAINT fk_transaction_invoice FOREIGN KEY (invoice_id) REFERENCES hekta_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_webhook_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    app_id VARCHAR(100) NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    attempt_number INT NOT NULL DEFAULT 1,
    request_payload JSON NOT NULL,
    request_headers JSON NULL,
    response_code INT NULL,
    response_body TEXT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT NULL,
    next_retry_at TIMESTAMP NULL,
    last_attempt_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_webhook_event (event_id),
    INDEX idx_webhook_retry (next_retry_at, success),
    CONSTRAINT fk_webhook_invoice FOREIGN KEY (invoice_id) REFERENCES hekta_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_webhook_retry_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    app_id VARCHAR(100) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 1,
    max_attempts INT NOT NULL DEFAULT 5,
    next_attempt_at TIMESTAMP NOT NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_retry_event (event_id),
    INDEX idx_retry_next_attempt (next_attempt_at),
    CONSTRAINT fk_retry_invoice FOREIGN KEY (invoice_id) REFERENCES hekta_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLLBACK:
-- DROP TABLE IF EXISTS hekta_webhook_retry_queue;
-- DROP TABLE IF EXISTS hekta_webhook_logs;
-- DROP TABLE IF EXISTS hekta_transactions;
-- DROP TABLE IF EXISTS hekta_invoices;
-- DROP TABLE IF EXISTS hekta_gateway_configs;
-- DROP TABLE IF EXISTS hekta_app_credentials;
