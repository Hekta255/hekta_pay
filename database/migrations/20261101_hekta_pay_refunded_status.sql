-- WHAT: Adds the `refunded` invoice status and a refunded_at timestamp.
-- WHY:  Google Play refunds and chargebacks must be recorded without deleting the original completed payment.

ALTER TABLE hekta_invoices
    MODIFY COLUMN status ENUM('pending','initiated','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    ADD COLUMN refunded_at TIMESTAMP NULL AFTER completed_at;

-- ROLLBACK:
-- UPDATE hekta_invoices SET status = 'completed' WHERE status = 'refunded';
-- ALTER TABLE hekta_invoices
--     DROP COLUMN refunded_at,
--     MODIFY COLUMN status ENUM('pending','initiated','completed','failed','cancelled') NOT NULL DEFAULT 'pending';
