-- Read-path indexes only; no business rows or lifecycle changes.
CREATE INDEX analytics_service_created ON merchant_service_sessions(merchant_id,outlet_id,created_epoch);
CREATE INDEX analytics_service_events ON merchant_service_events(merchant_id,outlet_id,occurred_epoch,operation);
CREATE INDEX analytics_seating_events ON merchant_seating_events(merchant_id,outlet_id,occurred_epoch);
CREATE INDEX analytics_reservation_created ON merchant_seating_reservations(merchant_id,outlet_id,created_epoch);
CREATE INDEX analytics_inventory_events ON merchant_inventory_events(merchant_id,outlet_id,occurred_epoch);
CREATE INDEX analytics_task_created ON preparation_tasks(outlet_id,created_at);
CREATE INDEX analytics_task_event_time ON preparation_task_events(outlet_id,changed_at);
