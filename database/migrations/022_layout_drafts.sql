CREATE TABLE merchant_layout_drafts (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 config_json TEXT NOT NULL,
 updated_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id,merchant_outlet_id) REFERENCES merchant_brand_profiles(merchant_id,merchant_outlet_id),
 CHECK(JSON_VALID(config_json))
) ENGINE=InnoDB;
