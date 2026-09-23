-- Persist the gateway environment selected by the consuming application.
ALTER TABLE hekta_invoices
    ADD COLUMN environment VARCHAR(10) NOT NULL DEFAULT 'test' AFTER gateway;

CREATE INDEX idx_invoice_environment
    ON hekta_invoices (environment);