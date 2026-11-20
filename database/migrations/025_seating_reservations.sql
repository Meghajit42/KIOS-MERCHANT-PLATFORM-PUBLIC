-- Internal M05B seating only. Existing inventory, orders and payments unchanged.
CREATE TABLE merchant_seating_settings (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 timezone_name VARCHAR(80) NOT NULL DEFAULT 'UTC',version BIGINT NOT NULL DEFAULT 1,
 PRIMARY KEY(merchant_id,outlet_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_seating_floors (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 floor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1,version BIGINT NOT NULL DEFAULT 1,
 PRIMARY KEY(merchant_id,outlet_id,floor_id),
 UNIQUE KEY seating_floor_name(merchant_id,outlet_id,name),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 CHECK(active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_seating_tables (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 table_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 floor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(80) NOT NULL,capacity SMALLINT NOT NULL,table_type VARCHAR(16) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,operational_state VARCHAR(16) NOT NULL DEFAULT 'AVAILABLE',
 notes VARCHAR(240) NOT NULL DEFAULT '',version BIGINT NOT NULL DEFAULT 1,
 operational_until BIGINT NULL,occupied_party SMALLINT NOT NULL DEFAULT 0,
 current_reservation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY(merchant_id,outlet_id,table_id),
 UNIQUE KEY seating_table_name(merchant_id,outlet_id,name),
 FOREIGN KEY(merchant_id,outlet_id,floor_id) REFERENCES merchant_seating_floors(merchant_id,outlet_id,floor_id),
 CHECK(capacity BETWEEN 1 AND 200),CHECK(active IN (0,1)),
 CHECK(occupied_party BETWEEN 0 AND capacity),
 CHECK(table_type IN ('standard','booth','bar','outdoor','private')),
 CHECK(operational_state IN ('AVAILABLE','RESERVED','OCCUPIED','OUT_OF_SERVICE'))
) ENGINE=InnoDB;
CREATE TABLE merchant_seating_reservations (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reservation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference_code CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 table_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 guest_name VARCHAR(80) NOT NULL,party_size SMALLINT NOT NULL,
 start_epoch BIGINT NOT NULL,end_epoch BIGINT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'REQUESTED',notes VARCHAR(240) NOT NULL DEFAULT '',
 checked_in_epoch BIGINT NULL,created_epoch BIGINT NOT NULL,version BIGINT NOT NULL DEFAULT 1,
 creator_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id,reservation_id),UNIQUE KEY seating_reference(reference_code),
 KEY seating_interval(merchant_id,outlet_id,table_id,status,start_epoch,end_epoch),
 FOREIGN KEY(merchant_id,outlet_id,table_id) REFERENCES merchant_seating_tables(merchant_id,outlet_id,table_id),
 FOREIGN KEY(creator_id) REFERENCES merchant_staff(user_id),
 CHECK(party_size BETWEEN 1 AND 200),CHECK(end_epoch>start_epoch),
 CHECK(status IN ('REQUESTED','CONFIRMED','SEATED','COMPLETED','CANCELLED','NO_SHOW'))
) ENGINE=InnoDB;
ALTER TABLE merchant_seating_tables ADD CONSTRAINT seating_current_reservation
 FOREIGN KEY(merchant_id,outlet_id,current_reservation) REFERENCES merchant_seating_reservations(merchant_id,outlet_id,reservation_id);
CREATE TABLE merchant_seating_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(20) NOT NULL,payload_hash CHAR(64) NOT NULL,
 result_json LONGTEXT NOT NULL,occurred_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id)
) ENGINE=InnoDB;
CREATE TRIGGER seating_events_no_update BEFORE UPDATE ON merchant_seating_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Seating events are immutable';
CREATE TRIGGER seating_events_no_delete BEFORE DELETE ON merchant_seating_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Seating events are immutable';
CREATE TRIGGER seating_reservations_no_delete BEFORE DELETE ON merchant_seating_reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reservation history must be retained';
