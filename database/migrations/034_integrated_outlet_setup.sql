ALTER TABLE merchant_onboarding ADD UNIQUE KEY integration_original_owner(merchant_id,owner_user_id);
ALTER TABLE merchant_brand_profiles
 DROP FOREIGN KEY merchant_brand_profiles_ibfk_1,
 DROP PRIMARY KEY,
 ADD PRIMARY KEY(merchant_id,merchant_outlet_id),
 ADD CONSTRAINT integrated_brand_mapping FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id) REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 ADD CONSTRAINT integrated_brand_original_owner FOREIGN KEY(merchant_id,owner_user_id) REFERENCES merchant_onboarding(merchant_id,owner_user_id);
CREATE TABLE merchant_outlet_configuration (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 profile_json TEXT NOT NULL,
 modules_json TEXT NOT NULL,
 reviewed TINYINT NOT NULL DEFAULT 0,
 updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_epoch BIGINT NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 FOREIGN KEY(updated_by) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(profile_json)),CHECK(JSON_VALID(modules_json)),CHECK(reviewed IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_integration_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 details_json LONGTEXT NOT NULL,
 reason VARCHAR(240) NOT NULL,
 created_epoch BIGINT NOT NULL,
 UNIQUE KEY integration_outlet_revision(merchant_id,outlet_id,version),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlet_configuration(merchant_id,outlet_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(details_json)),CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER integration_event_no_update BEFORE UPDATE ON merchant_integration_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Integration history is immutable';
CREATE TRIGGER integration_event_no_delete BEFORE DELETE ON merchant_integration_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Integration history is immutable';
