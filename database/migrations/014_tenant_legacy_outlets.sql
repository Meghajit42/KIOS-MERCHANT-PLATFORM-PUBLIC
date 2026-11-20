-- Explicit, immutable association for selected tenant integrations.
-- No legacy record is mapped automatically.
CREATE TABLE merchant_legacy_outlets (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(merchant_id,merchant_outlet_id),
 UNIQUE KEY uq_m01c_legacy_outlet(legacy_outlet_id),
 CONSTRAINT fk_m01c_identity_outlet FOREIGN KEY(merchant_id,merchant_outlet_id)
  REFERENCES merchant_outlets(merchant_id,outlet_id) ON DELETE RESTRICT,
 CONSTRAINT fk_m01c_legacy_outlet FOREIGN KEY(legacy_outlet_id)
  REFERENCES outlets(outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB;