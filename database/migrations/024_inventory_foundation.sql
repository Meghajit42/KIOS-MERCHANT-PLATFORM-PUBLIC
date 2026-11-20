-- M05A private inventory; no legacy catalogue/order changes.
CREATE TABLE merchant_inventory_items (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 item_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(120) NOT NULL, unit VARCHAR(8) NOT NULL,
 location_label VARCHAR(120) NOT NULL, supplier_reference VARCHAR(120) NOT NULL,
 balance_milli BIGINT NOT NULL DEFAULT 0, minimum_milli BIGINT NOT NULL DEFAULT 0,
 version BIGINT NOT NULL DEFAULT 1,
 PRIMARY KEY(merchant_id,outlet_id,item_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 CHECK(unit IN ('g','ml','each')), CHECK(balance_milli>=0), CHECK(minimum_milli>=0)
) ENGINE=InnoDB;
CREATE TABLE merchant_inventory_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(24) NOT NULL, payload_hash CHAR(64) NOT NULL,
 result_json LONGTEXT NOT NULL, occurred_epoch BIGINT NOT NULL,
 reversed_request CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 UNIQUE KEY inventory_event_scope(merchant_id,outlet_id,request_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(reversed_request) REFERENCES merchant_inventory_events(request_id)
) ENGINE=InnoDB;
CREATE TABLE merchant_inventory_movements (
 movement_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 item_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 delta_milli BIGINT NOT NULL, balance_after_milli BIGINT NOT NULL,
 FOREIGN KEY(merchant_id,outlet_id,request_id) REFERENCES merchant_inventory_events(merchant_id,outlet_id,request_id),
 FOREIGN KEY(merchant_id,outlet_id,item_id) REFERENCES merchant_inventory_items(merchant_id,outlet_id,item_id),
 UNIQUE KEY inventory_movement_item(request_id,item_id),
 CHECK(delta_milli<>0),CHECK(balance_after_milli>=0)
) ENGINE=InnoDB;
CREATE TABLE merchant_inventory_recipes (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recipe_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, revision BIGINT NOT NULL,
 lines_json LONGTEXT NOT NULL, request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(merchant_id,outlet_id,recipe_id),
 UNIQUE KEY inventory_recipe_version(merchant_id,outlet_id,product_id,revision),
 FOREIGN KEY(merchant_id,outlet_id,request_id) REFERENCES merchant_inventory_events(merchant_id,outlet_id,request_id)
) ENGINE=InnoDB;
CREATE TRIGGER inventory_event_no_update BEFORE UPDATE ON merchant_inventory_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory events are immutable';
CREATE TRIGGER inventory_event_no_delete BEFORE DELETE ON merchant_inventory_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory events are immutable';
CREATE TRIGGER inventory_movement_no_update BEFORE UPDATE ON merchant_inventory_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory movements are immutable';
CREATE TRIGGER inventory_movement_no_delete BEFORE DELETE ON merchant_inventory_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory movements are immutable';
CREATE TRIGGER inventory_recipe_no_update BEFORE UPDATE ON merchant_inventory_recipes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recipe versions are immutable';
CREATE TRIGGER inventory_recipe_no_delete BEFORE DELETE ON merchant_inventory_recipes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recipe versions are immutable';
