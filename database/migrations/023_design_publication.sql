CREATE TABLE merchant_design_versions (
 version_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json TEXT NOT NULL,
 snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_epoch BIGINT NOT NULL,
 UNIQUE(merchant_id,merchant_outlet_id,version_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id) REFERENCES merchant_brand_profiles(merchant_id,merchant_outlet_id),
 FOREIGN KEY(created_by) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(snapshot_json))
) ENGINE=InnoDB;
CREATE TRIGGER design_versions_immutable_update BEFORE UPDATE ON merchant_design_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published designs are immutable';
CREATE TRIGGER design_versions_immutable_delete BEFORE DELETE ON merchant_design_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published designs are immutable';
CREATE TABLE merchant_design_active (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(merchant_id,merchant_outlet_id,version_id) REFERENCES merchant_design_versions(merchant_id,merchant_outlet_id,version_id)
) ENGINE=InnoDB;
