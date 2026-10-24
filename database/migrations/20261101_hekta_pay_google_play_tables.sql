-- WHAT: Adds the Google Play Billing catalog, purchase ledger, and real-time notification event tables, and seeds the NafDex products.
-- WHY:  Google Play purchases are verified server-side and must map one purchase token to exactly one invoice and one plan term.

CREATE TABLE IF NOT EXISTS hekta_google_play_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id VARCHAR(100) NOT NULL,
    package_name VARCHAR(255) NOT NULL,
    product_id VARCHAR(100) NOT NULL,
    plan_id VARCHAR(50) NOT NULL,
    term_months INT NOT NULL,
    billing_period ENUM('monthly','yearly') NOT NULL,
    amount DECIMAL(12,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_play_product (app_id, product_id),
    INDEX idx_play_product_package (package_name, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_google_play_purchases (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    purchase_token_hash CHAR(64) NOT NULL,
    purchase_token_enc TEXT NOT NULL,
    order_id VARCHAR(100) NULL,
    product_id VARCHAR(100) NOT NULL,
    invoice_id CHAR(36) NOT NULL,
    app_id VARCHAR(100) NOT NULL,
    package_name VARCHAR(255) NOT NULL,
    purchase_state VARCHAR(30) NOT NULL,
    is_test BOOLEAN NOT NULL DEFAULT FALSE,
    region_code CHAR(2) NULL,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    webhook_confirmed_at TIMESTAMP NULL,
    consumed_at TIMESTAMP NULL,
    refunded_at TIMESTAMP NULL,
    refund_type VARCHAR(30) NULL,
    last_error TEXT NULL,
    raw_snapshot JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_play_token_hash (purchase_token_hash),
    UNIQUE KEY uk_play_invoice (invoice_id),
    INDEX idx_play_order (order_id),
    INDEX idx_play_unconsumed (consumed_at, refunded_at),
    CONSTRAINT fk_play_purchase_invoice FOREIGN KEY (invoice_id) REFERENCES hekta_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hekta_google_play_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(150) NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    package_name VARCHAR(255) NULL,
    token_hash CHAR(64) NULL,
    invoice_id CHAR(36) NULL,
    payload JSON NULL,
    processed_at TIMESTAMP NULL,
    error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_play_event_message (message_id),
    INDEX idx_play_event_type (notification_type, created_at),
    INDEX idx_play_event_invoice (invoice_id, notification_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hekta_google_play_products (app_id, package_name, product_id, plan_id, term_months, billing_period, amount, currency)
VALUES
    ('com.sebuleni.nafdex', 'com.sebuleni.nafdex', 'nafdex_shop_monthly', 'essential', 1, 'monthly', 1.9900, 'USD'),
    ('com.sebuleni.nafdex', 'com.sebuleni.nafdex', 'nafdex_shop_annual', 'essential', 12, 'yearly', 19.9000, 'USD')
ON DUPLICATE KEY UPDATE
    package_name = VALUES(package_name),
    plan_id = VALUES(plan_id),
    term_months = VALUES(term_months),
    billing_period = VALUES(billing_period),
    amount = VALUES(amount),
    currency = VALUES(currency);

DELETE FROM hekta_google_play_products
WHERE app_id = 'com.hekta.nafdex'
    AND package_name = 'com.sebuleni.nafdex';

-- ROLLBACK:
-- DROP TABLE IF EXISTS hekta_google_play_events;
-- DROP TABLE IF EXISTS hekta_google_play_purchases;
-- DROP TABLE IF EXISTS hekta_google_play_products;
