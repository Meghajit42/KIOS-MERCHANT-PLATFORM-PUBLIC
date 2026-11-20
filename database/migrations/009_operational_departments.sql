-- Add explicit department defaults and immutable order/task snapshots.
-- Existing product station_id remains the product-level routing override.
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS default_department_code VARCHAR(32) NULL AFTER name;

ALTER TABLE preparation_stations
    ADD COLUMN IF NOT EXISTS department_code VARCHAR(32) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS department_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER department_code,
    ADD KEY IF NOT EXISTS ix_stations_outlet_department (outlet_id, department_code, active);

-- Only explicitly approved route rows are customer-orderable. Category-level first-floor
-- proposals remain blocked until an Owner reviews the product and approves its route.
-- The routing backfill is not a product edit. Keep its historical ON UPDATE timestamp.
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS routing_status VARCHAR(24) NULL AFTER station_id;

UPDATE products p
JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id
SET p.routing_status = CASE
    WHEN c.slug IN ('dahi', 'extra', 'snacks') THEN 'owner_review'
    WHEN c.slug IN ('sweets', 'chat') THEN 'approved'
    ELSE 'proposed'
END,
    p.updated_at = p.updated_at
WHERE p.routing_status IS NULL;

ALTER TABLE products
    MODIFY COLUMN routing_status VARCHAR(24) NOT NULL DEFAULT 'owner_review';

-- Assign only categories whose floor is clear from the K10 brief and current menu taxonomy.
-- dahi, extra, snacks, and any future category remain unset for owner review.
UPDATE categories
SET default_department_code = CASE slug
    WHEN 'sweets' THEN 'GROUND_FLOOR'
    WHEN 'chat' THEN 'GROUND_FLOOR'
    WHEN 'beverage' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'gravy' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'momo' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'noodles' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'north-indian' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'roll' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'soup' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'south-indian' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'starter' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'tandoor' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'thali' THEN 'FIRST_FLOOR_KITCHEN'
    ELSE NULL
END
WHERE default_department_code IS NULL;

-- Station-level assignments take precedence over the category default. Product station_id
-- is already individually configurable in Owner Admin and therefore remains the override.
UPDATE preparation_stations
SET department_code = CASE code
    WHEN 'SWEETS' THEN 'GROUND_FLOOR'
    WHEN 'CHAAT' THEN 'GROUND_FLOOR'
    WHEN 'SOUTH_INDIAN' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'NORTH_INDIAN' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'MOMO' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'TANDOOR' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'GRAVY' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'NOODLES' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'BEVERAGE' THEN 'FIRST_FLOOR_KITCHEN'
    WHEN 'GENERAL_KITCHEN' THEN 'FIRST_FLOOR_KITCHEN'
    ELSE NULL
END
WHERE department_code IS NULL;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS department_code_snapshot VARCHAR(32) NULL AFTER station_id_snapshot;

UPDATE order_items oi
LEFT JOIN preparation_stations ps ON ps.outlet_id = oi.outlet_id AND ps.station_id = oi.station_id_snapshot
SET oi.department_code_snapshot = ps.department_code
WHERE oi.department_code_snapshot IS NULL;

ALTER TABLE preparation_tasks
    ADD COLUMN IF NOT EXISTS department_code_snapshot VARCHAR(32) NULL AFTER station_id,
    ADD KEY IF NOT EXISTS ix_preparation_tasks_department_queue (outlet_id, department_code_snapshot, status, created_at);

-- Assigning a floor snapshot is not a task status change.
UPDATE preparation_tasks pt
JOIN preparation_stations ps ON ps.outlet_id = pt.outlet_id AND ps.station_id = pt.station_id
SET pt.department_code_snapshot = ps.department_code,
    pt.updated_at = pt.updated_at
WHERE pt.department_code_snapshot IS NULL;

-- Some historic stations may not have a station-level mapping; use a unanimous item snapshot only.
UPDATE preparation_tasks pt
JOIN (
    SELECT pti.outlet_id, pti.order_id, pti.preparation_task_id,
        MIN(oi.department_code_snapshot) AS department_code,
        COUNT(DISTINCT oi.department_code_snapshot) AS department_count
    FROM preparation_task_items pti
    JOIN order_items oi ON oi.outlet_id = pti.outlet_id AND oi.order_id = pti.order_id
        AND oi.order_item_id = pti.order_item_id
    GROUP BY pti.outlet_id, pti.order_id, pti.preparation_task_id
) routed ON routed.outlet_id = pt.outlet_id AND routed.order_id = pt.order_id
    AND routed.preparation_task_id = pt.preparation_task_id
SET pt.department_code_snapshot = routed.department_code,
    pt.updated_at = pt.updated_at
WHERE pt.department_code_snapshot IS NULL AND routed.department_count = 1;

-- Attribute K10 status changes to the authenticated staff role, and retain each station
-- transition in the same transaction as the state change.
ALTER TABLE order_status_history
    ADD COLUMN IF NOT EXISTS changed_by VARCHAR(80) NULL AFTER status;

CREATE TABLE IF NOT EXISTS preparation_task_events (
    preparation_task_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outlet_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    preparation_task_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(24) NOT NULL,
    to_status VARCHAR(24) NOT NULL,
    changed_by VARCHAR(80) NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (preparation_task_event_id),
    KEY ix_preparation_task_events_task_time (outlet_id, preparation_task_id, changed_at),
    CONSTRAINT fk_preparation_task_events_task FOREIGN KEY (outlet_id, order_id, preparation_task_id)
        REFERENCES preparation_tasks (outlet_id, order_id, preparation_task_id) ON DELETE RESTRICT,
    CONSTRAINT chk_preparation_task_events_transition CHECK (
        (from_status = 'queued' AND to_status = 'preparing')
        OR (from_status = 'preparing' AND to_status = 'ready')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
