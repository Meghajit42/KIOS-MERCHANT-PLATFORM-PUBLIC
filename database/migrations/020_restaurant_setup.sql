CREATE TABLE merchant_restaurant_setup (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 owner_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id)
  REFERENCES merchant_onboarding(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_setup_staff (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 FOREIGN KEY(merchant_id) REFERENCES merchant_restaurant_setup(merchant_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 FOREIGN KEY(account_id,user_id,legacy_outlet_id)
  REFERENCES merchant_auth_links(account_id,user_id,legacy_outlet_id),
 CHECK(status IN ('pending','active','disabled'))
) ENGINE=InnoDB;
CREATE TABLE merchant_restaurant_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(32) NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json LONGTEXT NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id) REFERENCES merchant_restaurant_setup(merchant_id),
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(result_json))
) ENGINE=InnoDB;
