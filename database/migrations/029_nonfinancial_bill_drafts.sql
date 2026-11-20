CREATE TABLE merchant_bill_drafts (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, bill_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 service_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, version INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL,
 source_hash CHAR(64) NOT NULL, snapshot_json LONGTEXT NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id,bill_id), UNIQUE KEY bill_service(merchant_id,outlet_id,service_id),
 FOREIGN KEY(merchant_id,outlet_id,service_id) REFERENCES merchant_service_sessions(merchant_id,outlet_id,service_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_bill_revisions (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, bill_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL, request_id CHAR(32) NOT NULL UNIQUE, actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(16) NOT NULL, payload_hash CHAR(64) NOT NULL, snapshot_json LONGTEXT NOT NULL,
 occurred_epoch BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id,bill_id,version),
 FOREIGN KEY(merchant_id,outlet_id,bill_id) REFERENCES merchant_bill_drafts(merchant_id,outlet_id,bill_id), FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id)
) ENGINE=InnoDB;
CREATE TRIGGER bill_revision_no_update BEFORE UPDATE ON merchant_bill_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bill revisions are immutable';
CREATE TRIGGER bill_revision_no_delete BEFORE DELETE ON merchant_bill_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bill revisions are immutable';
