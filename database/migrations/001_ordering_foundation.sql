-- MariaDB/MySQL-compatible ordering foundation. All money amounts are integer paise.
-- Synthetic demo fixtures are supplied in application code; this migration inserts no shop data.

CREATE TABLE outlets (
    outlet_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_outlet_slug (outlet_id, slug),
    UNIQUE KEY uq_categories_outlet_id (outlet_id, category_id),
    CONSTRAINT fk_categories_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preparation_stations (
    station_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (station_id),
    UNIQUE KEY uq_stations_outlet_code (outlet_id, code),
    UNIQUE KEY uq_stations_outlet_id (outlet_id, station_id),
    CONSTRAINT fk_stations_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    product_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    image_ref VARCHAR(255) NULL,
    vegetarian TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    available TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    UNIQUE KEY uq_products_outlet_sku (outlet_id, sku),
    UNIQUE KEY uq_products_outlet_id (outlet_id, product_id),
    KEY ix_products_outlet_category (outlet_id, category_id, active, available),
    CONSTRAINT fk_products_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_category FOREIGN KEY (outlet_id, category_id) REFERENCES categories (outlet_id, category_id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_station FOREIGN KEY (outlet_id, station_id) REFERENCES preparation_stations (outlet_id, station_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
    variant_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(80) NOT NULL,
    label VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    quantity_value BIGINT UNSIGNED NOT NULL,
    price_paise BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (variant_id),
    UNIQUE KEY uq_variants_product_code (product_id, code),
    UNIQUE KEY uq_variants_product_id (product_id, variant_id),
    CONSTRAINT chk_variants_positive_quantity CHECK (quantity_value > 0),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(24) NOT NULL,
    order_type VARCHAR(24) NOT NULL,
    status VARCHAR(32) NOT NULL,
    total_paise BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_orders_token (token),
    UNIQUE KEY uq_orders_outlet_id (outlet_id, order_id),
    KEY ix_orders_outlet_status_created (outlet_id, status, created_at),
    CONSTRAINT chk_orders_type CHECK (order_type IN ('takeaway', 'dine_in')),
    CONSTRAINT chk_orders_status CHECK (status IN ('draft', 'awaiting_payment', 'paid', 'preparing', 'ready', 'collected', 'cancelled', 'expired')),
    CONSTRAINT fk_orders_outlet FOREIGN KEY (outlet_id) REFERENCES outlets (outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(180) NOT NULL,
    variant_label_snapshot VARCHAR(100) NOT NULL,
    unit_snapshot VARCHAR(20) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price_paise BIGINT UNSIGNED NOT NULL,
    line_total_paise BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (order_item_id),
    KEY ix_order_items_order (order_id),
    CONSTRAINT chk_order_items_positive_quantity CHECK (quantity > 0),
    CONSTRAINT fk_order_items_order FOREIGN KEY (outlet_id, order_id) REFERENCES orders (outlet_id, order_id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (outlet_id, product_id) REFERENCES products (outlet_id, product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_items_variant FOREIGN KEY (product_id, variant_id) REFERENCES product_variants (product_id, variant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY ix_order_status_history_order_time (order_id, changed_at),
    CONSTRAINT fk_order_history_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
