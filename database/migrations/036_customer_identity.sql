CREATE TABLE customer_accounts (
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 account_handle VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 display_name VARCHAR(100) NOT NULL,
 status ENUM('PENDING','ACTIVE','SUSPENDED','DEACTIVATED') NOT NULL,
 contact_state ENUM('UNVERIFIED') NOT NULL DEFAULT 'UNVERIFIED',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE customer_consents (
 consent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose ENUM('terms','privacy','marketing') NOT NULL,
 policy_version VARCHAR(32) NOT NULL, granted TINYINT UNSIGNED NOT NULL,
 account_version INT UNSIGNED NOT NULL, created_epoch BIGINT NOT NULL,
 FOREIGN KEY(customer_id) REFERENCES customer_accounts(customer_id) ON DELETE RESTRICT,
 CHECK(granted IN (0,1)), UNIQUE(customer_id,account_version)
) ENGINE=InnoDB;
CREATE TABLE customer_security_events (
 event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 customer_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_domain ENUM('PLATFORM','CUSTOMER') NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_code VARCHAR(32) NOT NULL, account_version INT UNSIGNED NOT NULL,
 created_epoch BIGINT NOT NULL,
 FOREIGN KEY(customer_id) REFERENCES customer_accounts(customer_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TRIGGER customer_event_immutable_update BEFORE UPDATE ON customer_security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customer audit immutable';
CREATE TRIGGER customer_event_immutable_delete BEFORE DELETE ON customer_security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customer audit immutable';
CREATE TRIGGER customer_consent_immutable_update BEFORE UPDATE ON customer_consents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customer consent immutable';
CREATE TRIGGER customer_consent_immutable_delete BEFORE DELETE ON customer_consents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customer consent immutable';
