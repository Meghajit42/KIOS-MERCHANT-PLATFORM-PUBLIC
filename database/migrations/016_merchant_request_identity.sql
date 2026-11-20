ALTER TABLE staff_accounts ADD UNIQUE KEY uq_m01e_account_outlet(outlet_id,account_id);
CREATE TABLE merchant_auth_links (
 account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(legacy_outlet_id,account_id) REFERENCES staff_accounts(outlet_id,account_id),
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 CHECK(active IN(0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_worker_grants (
 service_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability VARCHAR(32) NOT NULL DEFAULT 'virtual_print',
 active TINYINT NOT NULL DEFAULT 1,
 expires_at BIGINT NOT NULL,
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 CHECK(capability='virtual_print'),
 CHECK(active IN(0,1))
) ENGINE=InnoDB;