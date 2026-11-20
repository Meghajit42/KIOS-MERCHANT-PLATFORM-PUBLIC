CREATE TABLE merchant_service_sessions (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 service_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 table_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 table_version BIGINT NOT NULL,version BIGINT NOT NULL DEFAULT 1,
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',lines_json LONGTEXT NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,
 created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,
 active_table CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY(merchant_id,outlet_id,service_id),UNIQUE KEY active_table_service(merchant_id,outlet_id,active_table),UNIQUE KEY service_order(order_id),
 FOREIGN KEY(merchant_id,outlet_id,table_id) REFERENCES merchant_seating_tables(merchant_id,outlet_id,table_id),
 FOREIGN KEY(legacy_outlet_id,order_id) REFERENCES orders(outlet_id,order_id),
 CHECK(status IN ('DRAFT','SUBMITTED','SERVED','CANCELLED'))
) ENGINE=InnoDB;
CREATE TRIGGER service_slot_insert BEFORE INSERT ON merchant_service_sessions FOR EACH ROW SET NEW.active_table=IF(NEW.status IN ('DRAFT','SUBMITTED'),NEW.table_id,NULL);
CREATE TRIGGER service_slot_update BEFORE UPDATE ON merchant_service_sessions FOR EACH ROW BEGIN IF NEW.merchant_id<>OLD.merchant_id OR NEW.outlet_id<>OLD.outlet_id OR NEW.table_id<>OLD.table_id OR NEW.service_id<>OLD.service_id OR NEW.table_version<>OLD.table_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Service seating context is immutable'; END IF; SET NEW.active_table=IF(NEW.status IN ('DRAFT','SUBMITTED'),NEW.table_id,NULL); END;
CREATE TABLE merchant_service_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 service_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(24) NOT NULL,payload_hash CHAR(64) NOT NULL,result_json LONGTEXT NOT NULL,occurred_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id,outlet_id,service_id) REFERENCES merchant_service_sessions(merchant_id,outlet_id,service_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_service_line_notes (
 outlet_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,order_item_id BIGINT UNSIGNED NOT NULL,notes VARCHAR(240) NOT NULL,
 PRIMARY KEY(order_item_id),FOREIGN KEY(outlet_id,order_id,order_item_id) REFERENCES order_items(outlet_id,order_id,order_item_id)
) ENGINE=InnoDB;
CREATE TRIGGER service_events_no_update BEFORE UPDATE ON merchant_service_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Service audit is immutable';
CREATE TRIGGER service_events_no_delete BEFORE DELETE ON merchant_service_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Service audit is immutable';
CREATE TRIGGER service_notes_no_update BEFORE UPDATE ON merchant_service_line_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Submitted notes are immutable';
CREATE TRIGGER service_notes_no_delete BEFORE DELETE ON merchant_service_line_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Submitted notes are immutable';
CREATE TRIGGER service_sessions_no_delete BEFORE DELETE ON merchant_service_sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Service history is retained';
