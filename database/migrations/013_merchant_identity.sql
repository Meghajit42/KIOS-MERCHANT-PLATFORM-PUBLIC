-- Internal M01B identity only. Legacy business tables are unchanged.
CREATE TABLE merchant_organizations (
 CHECK (merchant_id REGEXP '^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'),
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 name VARCHAR(200) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 CONSTRAINT chk_m01b_merchant_active CHECK (active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_outlets (
 CHECK (outlet_id REGEXP '^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'),
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(200) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_m01b_outlet_owner(merchant_id,outlet_id),
 CONSTRAINT fk_m01b_outlet_owner FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),
 CONSTRAINT chk_m01b_outlet_active CHECK(active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_staff (
 CHECK (user_id REGEXP '^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'),
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 display_name VARCHAR(200) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 CONSTRAINT chk_m01b_staff_active CHECK(active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_roles (
 role_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
CREATE TABLE merchant_permissions (
 permission_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
CREATE TABLE merchant_role_permissions (
 role_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 permission_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(role_code,permission_code),
 FOREIGN KEY(role_code) REFERENCES merchant_roles(role_code),
 FOREIGN KEY(permission_code) REFERENCES merchant_permissions(permission_code)
) ENGINE=InnoDB;
CREATE TABLE merchant_memberships (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 PRIMARY KEY(user_id,merchant_id),
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(merchant_id) REFERENCES merchant_organizations(merchant_id),
 FOREIGN KEY(role_code) REFERENCES merchant_roles(role_code),
 CHECK(role_code='merchant_owner'),
 CHECK(active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_outlet_memberships (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 PRIMARY KEY(user_id,merchant_id,outlet_id),
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(merchant_id,outlet_id) REFERENCES merchant_outlets(merchant_id,outlet_id),
 FOREIGN KEY(role_code) REFERENCES merchant_roles(role_code),
 CHECK(role_code IN ('outlet_administrator','cashier','kitchen')),
 CHECK(active IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE merchant_platform_memberships (
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 role_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'platform_administrator',
 active TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(user_id) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(role_code) REFERENCES merchant_roles(role_code),
 CHECK(role_code='platform_administrator'),
 CHECK(active IN (0,1))
) ENGINE=InnoDB;
INSERT INTO merchant_roles VALUES ('platform_administrator'),('merchant_owner'),('outlet_administrator'),('cashier'),('kitchen');
INSERT INTO merchant_permissions VALUES ('manage_platform'),('manage_merchant'),('manage_outlet'),('manage_staff'),('read_orders'),('record_cash'),('prepare_orders');
INSERT INTO merchant_role_permissions VALUES
('platform_administrator','manage_platform'),
('merchant_owner','manage_merchant'),('merchant_owner','manage_outlet'),('merchant_owner','manage_staff'),('merchant_owner','read_orders'),('merchant_owner','record_cash'),('merchant_owner','prepare_orders'),
('outlet_administrator','manage_outlet'),('outlet_administrator','manage_staff'),('outlet_administrator','read_orders'),('outlet_administrator','record_cash'),('outlet_administrator','prepare_orders'),
('cashier','read_orders'),('cashier','record_cash'),('kitchen','read_orders'),('kitchen','prepare_orders');