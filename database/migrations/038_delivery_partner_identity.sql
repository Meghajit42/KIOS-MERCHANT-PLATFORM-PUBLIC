-- Internal synthetic delivery identity only. No assignment, delivery order or dispatch tables.
CREATE TABLE delivery_partner_accounts (
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 partner_handle VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 display_name VARCHAR(100) NOT NULL,
 status ENUM('PENDING','ACTIVE','SUSPENDED','DEACTIVATED') NOT NULL,
 eligibility ENUM('INELIGIBLE','ELIGIBLE') NOT NULL,
 availability ENUM('OFFLINE','AVAILABLE','TEMPORARILY_UNAVAILABLE') NOT NULL,
 service_areas_json LONGTEXT NOT NULL CHECK(JSON_VALID(service_areas_json)),
 capabilities_json LONGTEXT NOT NULL CHECK(JSON_VALID(capabilities_json)),
 version INT UNSIGNED NOT NULL CHECK(version>0),
 created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
 CHECK(eligibility='INELIGIBLE' OR status='ACTIVE'),
 CHECK(availability='OFFLINE' OR status='ACTIVE'),
 CHECK(availability<>'AVAILABLE' OR eligibility='ELIGIBLE')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE delivery_partner_events (
 event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_domain ENUM('PLATFORM','PARTNER') NOT NULL,
 actor_partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_staff_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 action_code VARCHAR(48) NOT NULL,
 partner_version INT UNSIGNED NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 UNIQUE(partner_id,partner_version),
 FOREIGN KEY(partner_id) REFERENCES delivery_partner_accounts(partner_id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_partner_id) REFERENCES delivery_partner_accounts(partner_id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_staff_id) REFERENCES merchant_staff(user_id) ON DELETE RESTRICT,
 CHECK((actor_domain='PARTNER' AND actor_partner_id=partner_id AND actor_partner_id IS NOT NULL AND actor_staff_id IS NULL)
    OR (actor_domain='PLATFORM' AND actor_staff_id IS NOT NULL AND actor_partner_id IS NULL))
) ENGINE=InnoDB;
CREATE TRIGGER partner_event_no_update BEFORE UPDATE ON delivery_partner_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner audit immutable';
CREATE TRIGGER partner_event_no_delete BEFORE DELETE ON delivery_partner_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner audit immutable';
CREATE TRIGGER partner_account_no_delete BEFORE DELETE ON delivery_partner_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner identity retained';
CREATE TRIGGER partner_identity_immutable BEFORE UPDATE ON delivery_partner_accounts FOR EACH ROW BEGIN IF NOT(OLD.partner_id<=>NEW.partner_id) OR NOT(OLD.partner_handle<=>NEW.partner_handle) OR OLD.created_epoch<>NEW.created_epoch THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner identity immutable'; END IF; END;
