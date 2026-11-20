CREATE TABLE merchant_subscription_events (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 assignment_head INT UNSIGNED NOT NULL,
 action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 from_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 to_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 config_json LONGTEXT NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(240) NOT NULL,
 created_epoch BIGINT NOT NULL,
 PRIMARY KEY(merchant_id,version),
 FOREIGN KEY(merchant_id,assignment_head) REFERENCES merchant_plan_assignments(merchant_id,version),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(config_json)), CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TRIGGER subscription_event_no_update BEFORE UPDATE ON merchant_subscription_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Subscription lifecycle history is immutable';
CREATE TRIGGER subscription_event_no_delete BEFORE DELETE ON merchant_subscription_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Subscription lifecycle history is immutable';
