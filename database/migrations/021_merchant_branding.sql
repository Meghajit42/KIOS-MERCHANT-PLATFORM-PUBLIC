CREATE TABLE merchant_brand_profiles (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 owner_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 config_json TEXT NOT NULL,
 current_asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 UNIQUE(merchant_id,merchant_outlet_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id)
  REFERENCES merchant_onboarding(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id),
 CHECK(JSON_VALID(config_json))
) ENGINE=InnoDB;
CREATE TABLE merchant_brand_assets (
 asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'pending',
 original_mime VARCHAR(32) NOT NULL,
 original_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_bytes MEDIUMBLOB NOT NULL,
 safe_png MEDIUMBLOB NOT NULL,
 safe_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 width INT UNSIGNED NOT NULL,
 height INT UNSIGNED NOT NULL,
 created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_epoch BIGINT NOT NULL,
 UNIQUE(merchant_id,merchant_outlet_id,asset_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id) REFERENCES merchant_brand_profiles(merchant_id,merchant_outlet_id),
 FOREIGN KEY(created_by) REFERENCES merchant_staff(user_id),
 CHECK(state IN ('pending','retained','cancelled')),
 CHECK(OCTET_LENGTH(original_bytes) BETWEEN 1 AND 1048576),
 CHECK(OCTET_LENGTH(safe_png) BETWEEN 1 AND 1048576),
 CHECK(width BETWEEN 1 AND 512 AND height BETWEEN 1 AND 512)
) ENGINE=InnoDB;
ALTER TABLE merchant_brand_profiles ADD CONSTRAINT brand_current_owned_asset
 FOREIGN KEY(merchant_id,merchant_outlet_id,current_asset_id)
 REFERENCES merchant_brand_assets(merchant_id,merchant_outlet_id,asset_id);
CREATE TABLE merchant_brand_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(24) NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json TEXT NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id,merchant_outlet_id) REFERENCES merchant_brand_profiles(merchant_id,merchant_outlet_id),
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(result_json))
) ENGINE=InnoDB;
