CREATE TABLE merchant_platform_controls (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),
 CHECK(status IN ('PROVISIONING','ACTIVE_DEMO','SUSPENDED','ARCHIVED'))
) ENGINE=InnoDB;
CREATE TABLE merchant_platform_outlets (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 CHECK(status IN ('ACTIVE_DEMO','SUSPENDED','ARCHIVED'))
) ENGINE=InnoDB;
CREATE TABLE merchant_platform_events (
 event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 details_json LONGTEXT NOT NULL,
 reason VARCHAR(240) NOT NULL,
 created_epoch BIGINT NOT NULL,
 UNIQUE KEY platform_merchant_revision(merchant_id,version),
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),
 CHECK(version>0),CHECK(JSON_VALID(details_json)),
 KEY platform_event_time(created_epoch,event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TRIGGER platform_event_no_update BEFORE UPDATE ON merchant_platform_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Platform administrative audit is immutable';
CREATE TRIGGER platform_event_no_delete BEFORE DELETE ON merchant_platform_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Platform administrative audit is immutable';
