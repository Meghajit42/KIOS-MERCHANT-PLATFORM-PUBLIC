-- K6 menu administration. Existing order-item business snapshots are preserved;
-- station routing is captured at checkout so later menu edits affect future orders only.

ALTER TABLE categories
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER sort_order;

ALTER TABLE products
    ADD COLUMN display_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER available,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER display_order,
    ADD COLUMN image_width SMALLINT UNSIGNED NULL AFTER image_ref,
    ADD COLUMN image_height SMALLINT UNSIGNED NULL AFTER image_width;

UPDATE products SET display_order = product_id;

ALTER TABLE product_variants
    ADD COLUMN display_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER active,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER display_order,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER version,
    ADD KEY ix_variants_product_label_active (product_id, label, active);

UPDATE product_variants SET display_order = variant_id;

ALTER TABLE order_items
    ADD COLUMN station_id_snapshot BIGINT UNSIGNED NULL AFTER variant_code_snapshot;

UPDATE order_items oi
INNER JOIN preparation_task_items pti
    ON pti.outlet_id = oi.outlet_id AND pti.order_id = oi.order_id AND pti.order_item_id = oi.order_item_id
INNER JOIN preparation_tasks pt
    ON pt.outlet_id = pti.outlet_id AND pt.order_id = pti.order_id AND pt.preparation_task_id = pti.preparation_task_id
SET oi.station_id_snapshot = pt.station_id
WHERE oi.station_id_snapshot IS NULL;

UPDATE order_items oi
INNER JOIN products p ON p.outlet_id = oi.outlet_id AND p.product_id = oi.product_id
SET oi.station_id_snapshot = p.station_id
WHERE oi.station_id_snapshot IS NULL;

ALTER TABLE order_items
    MODIFY station_id_snapshot BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_order_items_station_snapshot FOREIGN KEY (outlet_id, station_id_snapshot)
        REFERENCES preparation_stations (outlet_id, station_id) ON DELETE RESTRICT;

CREATE TABLE outlet_menu_settings (
    outlet_id BIGINT UNSIGNED NOT NULL,
    preparation_notice VARCHAR(80) NOT NULL DEFAULT '15–20 mins',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (outlet_id),
    CONSTRAINT fk_menu_settings_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO outlet_menu_settings (outlet_id, preparation_notice)
SELECT outlet_id, '15–20 mins' FROM outlets;

CREATE TABLE menu_admin_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    entity_type VARCHAR(24) NOT NULL,
    entity_id VARCHAR(80) NOT NULL,
    before_summary VARCHAR(500) NULL,
    after_summary VARCHAR(500) NOT NULL,
    actor_ref VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY ix_menu_admin_events_outlet_time (outlet_id, created_at, event_id),
    CONSTRAINT fk_menu_admin_events_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
