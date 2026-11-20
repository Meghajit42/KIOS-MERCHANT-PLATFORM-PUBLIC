-- Store one durable preparation task per paid order and station, with same-order item links.
ALTER TABLE order_items
    ADD UNIQUE KEY uq_order_items_outlet_order_item (outlet_id, order_id, order_item_id);

CREATE TABLE preparation_tasks (
    preparation_task_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    station_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (preparation_task_id),
    UNIQUE KEY uq_preparation_tasks_order_station (outlet_id, order_id, station_id),
    UNIQUE KEY uq_preparation_tasks_scope_id (outlet_id, order_id, preparation_task_id),
    KEY ix_preparation_tasks_station_queue (outlet_id, station_id, status, created_at),
    CONSTRAINT chk_preparation_tasks_status CHECK (status IN ('queued', 'preparing', 'ready')),
    CONSTRAINT fk_preparation_tasks_outlet_order FOREIGN KEY (outlet_id, order_id)
        REFERENCES orders (outlet_id, order_id) ON DELETE RESTRICT,
    CONSTRAINT fk_preparation_tasks_outlet_station FOREIGN KEY (outlet_id, station_id)
        REFERENCES preparation_stations (outlet_id, station_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preparation_task_items (
    preparation_task_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    preparation_task_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (preparation_task_item_id),
    UNIQUE KEY uq_preparation_task_items_task_order_item (preparation_task_id, order_item_id),
    UNIQUE KEY uq_preparation_task_items_order_item (outlet_id, order_id, order_item_id),
    KEY ix_preparation_task_items_scope (outlet_id, order_id, preparation_task_id),
    KEY ix_preparation_task_items_order_item (outlet_id, order_id, order_item_id),
    CONSTRAINT fk_preparation_task_items_task FOREIGN KEY (outlet_id, order_id, preparation_task_id)
        REFERENCES preparation_tasks (outlet_id, order_id, preparation_task_id) ON DELETE RESTRICT,
    CONSTRAINT fk_preparation_task_items_order_item FOREIGN KEY (outlet_id, order_id, order_item_id)
        REFERENCES order_items (outlet_id, order_id, order_item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
