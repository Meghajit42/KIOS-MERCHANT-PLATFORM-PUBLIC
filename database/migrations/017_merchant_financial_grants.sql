CREATE TABLE merchant_financial_grants (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 capability VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 PRIMARY KEY(user_id,merchant_outlet_id,capability),
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 CHECK(capability IN ('read_reconciliation','close_day')),
 CHECK(active IN (0,1))
) ENGINE=InnoDB;
