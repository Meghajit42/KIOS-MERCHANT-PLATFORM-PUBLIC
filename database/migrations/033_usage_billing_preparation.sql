CREATE TABLE merchant_pricing_heads (
 plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_version INT UNSIGNED NOT NULL,
 cycle VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(plan_id,plan_version,cycle),
 FOREIGN KEY(plan_id,plan_version) REFERENCES merchant_plan_versions(plan_id,version),
 CHECK(cycle IN ('MONTHLY','ANNUAL'))
) ENGINE=InnoDB;
CREATE TABLE merchant_pricing_versions (
 price_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_version INT UNSIGNED NOT NULL,
 cycle VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 subscription_paise BIGINT NOT NULL, fee_paise BIGINT NOT NULL, support_paise BIGINT NOT NULL,
 effective_epoch BIGINT NOT NULL,
 UNIQUE KEY pricing_version(plan_id,plan_version,cycle,version),
 FOREIGN KEY(plan_id,plan_version,cycle) REFERENCES merchant_pricing_heads(plan_id,plan_version,cycle),
 CHECK(subscription_paise BETWEEN 0 AND 12000000), CHECK(fee_paise BETWEEN 0 AND 300), CHECK(support_paise BETWEEN 0 AND 1000000)
) ENGINE=InnoDB;
CREATE TABLE merchant_meter_heads (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_usage_ledger (
 event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 classification VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 period_key CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 units INT NOT NULL, adjustment_paise BIGINT NOT NULL,
 reference_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 UNIQUE KEY usage_source(merchant_id,source_id), UNIQUE KEY usage_reversal(reference_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 FOREIGN KEY(reference_id) REFERENCES merchant_usage_ledger(event_id),
 CHECK(units BETWEEN -1 AND 1), CHECK(adjustment_paise BETWEEN -1000000 AND 1000000),
 CHECK(classification IN ('SIMULATED_ELIGIBLE','INTERNAL_SERVICE','BILL_DRAFT','KOT','DEMO_EXCLUDED','ADJUSTMENT','REVERSAL')),
 KEY usage_period(merchant_id,occurred_epoch,event_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_billing_previews (
 preview_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_version INT UNSIGNED NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),CHECK(JSON_VALID(snapshot_json))
) ENGINE=InnoDB;
CREATE TABLE merchant_billing_audit (
 audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(240) NOT NULL,
 created_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),
 KEY billing_audit_merchant(merchant_id,audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER pricing_no_update BEFORE UPDATE ON merchant_pricing_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Illustrative pricing history is immutable';
CREATE TRIGGER pricing_no_delete BEFORE DELETE ON merchant_pricing_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Illustrative pricing history is immutable';
CREATE TRIGGER usage_no_update BEFORE UPDATE ON merchant_usage_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Usage ledger is immutable';
CREATE TRIGGER usage_no_delete BEFORE DELETE ON merchant_usage_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Usage ledger is immutable';
CREATE TRIGGER billing_preview_no_update BEFORE UPDATE ON merchant_billing_previews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Saved estimate is immutable';
CREATE TRIGGER billing_preview_no_delete BEFORE DELETE ON merchant_billing_previews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Saved estimate is immutable';
CREATE TRIGGER billing_audit_no_update BEFORE UPDATE ON merchant_billing_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Billing preparation audit is immutable';
CREATE TRIGGER billing_audit_no_delete BEFORE DELETE ON merchant_billing_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Billing preparation audit is immutable';
