CREATE TABLE merchant_plan_versions (
 plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, version INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL, description VARCHAR(500) NOT NULL, active TINYINT NOT NULL,
 features_json LONGTEXT NOT NULL, limits_json LONGTEXT NOT NULL, metadata_paise BIGINT UNSIGNED NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_id CHAR(32) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, created_epoch BIGINT NOT NULL,
 PRIMARY KEY(plan_id,version), FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),CHECK(active IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE merchant_plan_heads (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, version INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_plan_assignments (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, version INT UNSIGNED NOT NULL,
 plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, plan_version INT UNSIGNED NOT NULL,
 effective_epoch BIGINT NOT NULL, expires_epoch BIGINT NOT NULL, actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, reason VARCHAR(240) NOT NULL, created_epoch BIGINT NOT NULL,
 PRIMARY KEY(merchant_id,version),FOREIGN KEY(merchant_id) REFERENCES merchant_plan_heads(merchant_id),
 FOREIGN KEY(plan_id,plan_version) REFERENCES merchant_plan_versions(plan_id,version),FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),CHECK(expires_epoch>effective_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TRIGGER plan_version_no_update BEFORE UPDATE ON merchant_plan_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Plan versions are immutable';
CREATE TRIGGER plan_version_no_delete BEFORE DELETE ON merchant_plan_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Plan versions are immutable';
CREATE TRIGGER plan_assignment_no_update BEFORE UPDATE ON merchant_plan_assignments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Assignment history is immutable';
CREATE TRIGGER plan_assignment_no_delete BEFORE DELETE ON merchant_plan_assignments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Assignment history is immutable';
