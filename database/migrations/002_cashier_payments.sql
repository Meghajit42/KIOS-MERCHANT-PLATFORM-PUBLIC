-- Add stable application references and a single cashier-confirmed payment per order.
ALTER TABLE outlets
    ADD COLUMN outlet_code VARCHAR(80) NULL AFTER outlet_id;

UPDATE outlets
SET outlet_code = CONCAT('outlet-', outlet_id)
WHERE outlet_code IS NULL;

ALTER TABLE outlets
    MODIFY outlet_code VARCHAR(80) NOT NULL,
    ADD UNIQUE KEY uq_outlets_code (outlet_code);

ALTER TABLE orders
    ADD COLUMN public_id VARCHAR(40) NULL AFTER order_id,
    ADD COLUMN outlet_code_snapshot VARCHAR(80) NULL AFTER outlet_id;

UPDATE orders
INNER JOIN outlets o ON o.outlet_id = orders.outlet_id
SET public_id = COALESCE(public_id, CONCAT('O-', REPLACE(UPPER(UUID()), '-', ''))),
    outlet_code_snapshot = COALESCE(outlet_code_snapshot, o.outlet_code)
WHERE public_id IS NULL OR outlet_code_snapshot IS NULL;

ALTER TABLE orders
    MODIFY public_id VARCHAR(40) NOT NULL,
    MODIFY outlet_code_snapshot VARCHAR(80) NOT NULL,
    ADD UNIQUE KEY uq_orders_public_id (public_id);

ALTER TABLE order_items
    ADD COLUMN product_sku_snapshot VARCHAR(80) NULL AFTER variant_id,
    ADD COLUMN variant_code_snapshot VARCHAR(80) NULL AFTER product_sku_snapshot;

UPDATE order_items oi
INNER JOIN products p ON p.product_id = oi.product_id
INNER JOIN product_variants pv ON pv.variant_id = oi.variant_id AND pv.product_id = oi.product_id
SET oi.product_sku_snapshot = p.sku,
    oi.variant_code_snapshot = pv.code
WHERE oi.product_sku_snapshot IS NULL OR oi.variant_code_snapshot IS NULL;

ALTER TABLE order_items
    MODIFY product_sku_snapshot VARCHAR(80) NOT NULL,
    MODIFY variant_code_snapshot VARCHAR(80) NOT NULL;

CREATE TABLE payment_confirmations (
    payment_confirmation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(16) NOT NULL,
    amount_paise BIGINT UNSIGNED NOT NULL,
    confirmed_by VARCHAR(80) NOT NULL,
    confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_confirmation_id),
    UNIQUE KEY uq_payment_confirmations_order (order_id),
    CONSTRAINT chk_payment_confirmations_method CHECK (payment_method IN ('cash', 'upi', 'card', 'other')),
    CONSTRAINT fk_payment_confirmations_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
