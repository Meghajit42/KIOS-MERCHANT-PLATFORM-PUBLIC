ALTER TABLE merchant_legacy_outlets
 ADD UNIQUE KEY uq_m01d_mapping(merchant_id,merchant_outlet_id,legacy_outlet_id);
CREATE TABLE merchant_kitchen_grants (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 station_id BIGINT UNSIGNED NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 PRIMARY KEY(user_id,merchant_outlet_id,station_id),
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 FOREIGN KEY(legacy_outlet_id,station_id) REFERENCES preparation_stations(outlet_id,station_id),
 CHECK(active IN(0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_operation_events (
 event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(50) NOT NULL,
 resource_id BIGINT UNSIGNED NOT NULL,
 business_date DATE NOT NULL,
 occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 KEY ix_m01d_audit(merchant_id,merchant_outlet_id,business_date,event_id)
) ENGINE=InnoDB;