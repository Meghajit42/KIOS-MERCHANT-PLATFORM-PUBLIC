-- Isolated simulation records only. No operational orders, KOTs or financial entries.
CREATE TABLE customer_order_intents (
 intent_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('DRAFT','SUBMITTED','PENDING_MERCHANT_ACCEPTANCE','ACCEPTED','REJECTED','CANCELLED','EXPIRED') NOT NULL,
 version INT UNSIGNED NOT NULL,
 total_paise BIGINT UNSIGNED NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(snapshot_json)),
 created_epoch BIGINT NOT NULL, expires_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 UNIQUE(intent_id,legacy_outlet_id),
 UNIQUE(intent_id,customer_id,merchant_id,outlet_id),
 CHECK(version>0 AND expires_epoch>created_epoch),
 FOREIGN KEY(customer_id) REFERENCES customer_accounts(customer_id) ON DELETE RESTRICT,
 FOREIGN KEY(merchant_id,outlet_id,legacy_outlet_id) REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE customer_order_intent_lines (
 intent_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL CHECK(quantity BETWEEN 1 AND 99),
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(intent_id,product_id,variant_id),
 FOREIGN KEY(intent_id,legacy_outlet_id) REFERENCES customer_order_intents(intent_id,legacy_outlet_id) ON DELETE RESTRICT,
 FOREIGN KEY(legacy_outlet_id,product_id) REFERENCES products(outlet_id,product_id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id,variant_id) REFERENCES product_variants(product_id,variant_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE customer_order_intent_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 intent_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_code VARCHAR(32) NOT NULL, payload_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 intent_version INT UNSIGNED NOT NULL, result_json LONGTEXT NOT NULL CHECK(JSON_VALID(result_json)),
 created_epoch BIGINT NOT NULL, mode ENUM('SIMULATION') NOT NULL,
 UNIQUE(intent_id,intent_version),
 FOREIGN KEY(intent_id,customer_id,merchant_id,outlet_id) REFERENCES customer_order_intents(intent_id,customer_id,merchant_id,outlet_id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_id) REFERENCES customer_accounts(customer_id) ON DELETE RESTRICT,
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TRIGGER customer_intent_event_no_update BEFORE UPDATE ON customer_order_intent_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation audit immutable';
CREATE TRIGGER customer_intent_event_no_delete BEFORE DELETE ON customer_order_intent_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation audit immutable';
CREATE TRIGGER customer_intent_line_no_update BEFORE UPDATE ON customer_order_intent_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation selection immutable';
CREATE TRIGGER customer_intent_line_no_delete BEFORE DELETE ON customer_order_intent_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation selection immutable';
CREATE TRIGGER customer_intent_no_delete BEFORE DELETE ON customer_order_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation history retained';
CREATE TRIGGER customer_intent_snapshot_immutable BEFORE UPDATE ON customer_order_intents FOR EACH ROW BEGIN IF NOT(OLD.snapshot_json<=>NEW.snapshot_json) OR OLD.total_paise<>NEW.total_paise OR OLD.customer_id<>NEW.customer_id OR OLD.merchant_id<>NEW.merchant_id OR OLD.outlet_id<>NEW.outlet_id OR OLD.intent_id<>NEW.intent_id OR OLD.legacy_outlet_id<>NEW.legacy_outlet_id OR OLD.created_epoch<>NEW.created_epoch OR OLD.expires_epoch<>NEW.expires_epoch THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulation snapshot immutable'; END IF; END;

