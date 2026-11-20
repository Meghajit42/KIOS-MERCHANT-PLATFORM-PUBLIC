-- Adds a separate public pickup token. Existing order IDs/tokens and history are unchanged.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS pickup_token VARCHAR(20) NULL AFTER token,
    ADD COLUMN IF NOT EXISTS pickup_business_date DATE NULL AFTER pickup_token,
    ADD UNIQUE KEY IF NOT EXISTS uq_orders_outlet_day_pickup (outlet_id, pickup_business_date, pickup_token);

CREATE TABLE IF NOT EXISTS pickup_token_counters (
    outlet_id BIGINT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 101,
    PRIMARY KEY (outlet_id, business_date),
    CONSTRAINT chk_pickup_token_counter_min CHECK (next_number >= 101),
    CONSTRAINT fk_pickup_token_counter_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
