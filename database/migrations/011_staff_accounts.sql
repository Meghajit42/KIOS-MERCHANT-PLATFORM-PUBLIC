-- Append-only account authentication; no legacy credential is copied by this migration.
CREATE TABLE IF NOT EXISTS staff_accounts (
 account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 outlet_id BIGINT UNSIGNED NOT NULL,
 realm VARCHAR(16) NOT NULL,
 username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(100) NOT NULL,
 role VARCHAR(32) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 enabled SMALLINT NOT NULL DEFAULT 1,
 must_change SMALLINT NOT NULL DEFAULT 1,
 auth_version BIGINT NOT NULL DEFAULT 1,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 UNIQUE KEY uq_staff_identity (outlet_id, realm, username),
 CONSTRAINT fk_staff_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id),
 CONSTRAINT chk_staff_realm_role CHECK ((realm='staff' AND role IN ('cashier','ground_floor','first_floor_kitchen')) OR (realm='owner' AND role='owner')),
 CONSTRAINT chk_staff_flags CHECK (enabled IN (0,1) AND must_change IN (0,1) AND auth_version>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_auth_recovery_operations (
 operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 outlet_id BIGINT UNSIGNED NOT NULL,
 target_account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at BIGINT NOT NULL,
 CONSTRAINT fk_staff_recovery_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_auth_audit (
 event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 outlet_id BIGINT UNSIGNED NOT NULL,
 actor VARCHAR(100) NOT NULL,
 target_account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 action VARCHAR(48) NOT NULL,
 occurred_at BIGINT NOT NULL,
 KEY ix_staff_audit_outlet (outlet_id, occurred_at),
 CONSTRAINT fk_staff_audit_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_auth_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 failures INT NOT NULL DEFAULT 0,
 window_start BIGINT NOT NULL,
 locked_until BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
