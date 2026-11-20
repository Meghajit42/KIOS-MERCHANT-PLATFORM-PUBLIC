CREATE TABLE shop_operations (
 outlet_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 opening_time CHAR(5) NOT NULL DEFAULT '08:00', closing_time CHAR(5) NOT NULL DEFAULT '22:00',
 manual_date DATE NULL, manual_enabled TINYINT NOT NULL DEFAULT 0, day_ended TINYINT NOT NULL DEFAULT 0,
 version INTEGER NOT NULL DEFAULT 1, unpaid_minutes INTEGER NOT NULL DEFAULT 15,
 ready_warning_minutes INTEGER NOT NULL DEFAULT 30, exception_minutes INTEGER NOT NULL DEFAULT 120,
 collected_seconds INTEGER NOT NULL DEFAULT 180, aging_minutes INTEGER NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE shop_operation_events (
 event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, outlet_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(32) NOT NULL, request_hash CHAR(64) NOT NULL, action VARCHAR(40) NOT NULL,
 actor_ref VARCHAR(80) NOT NULL, occurred_epoch BIGINT NOT NULL, detail_json LONGTEXT NOT NULL,
 UNIQUE KEY shop_request (outlet_id,request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE shop_day_closings (
 closing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, outlet_id BIGINT UNSIGNED NOT NULL,
 business_date DATE NOT NULL, request_id CHAR(32) NOT NULL, closed_epoch BIGINT NOT NULL,
 summary_json LONGTEXT NOT NULL, UNIQUE KEY shop_closing_request (outlet_id,request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE shop_order_acceptance (
 order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, outlet_id BIGINT UNSIGNED NOT NULL,
 accepted_epoch BIGINT NOT NULL, unpaid_deadline_epoch BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
