ALTER TABLE merchant_auth_links
 ADD UNIQUE KEY uq_m02a_auth_binding(account_id,user_id,legacy_outlet_id);
CREATE TABLE merchant_onboarding (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL UNIQUE,
 owner_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 owner_account_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 business_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_contact VARCHAR(254) NOT NULL,
 status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_epoch BIGINT NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INTEGER NOT NULL DEFAULT 1,
 activated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 activated_epoch BIGINT NULL,
 activation_request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 KEY ix_m02a_creator(created_by,created_epoch),
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id)
  REFERENCES merchant_legacy_outlets(merchant_id,merchant_outlet_id,legacy_outlet_id),
 FOREIGN KEY(owner_account_id,owner_user_id,legacy_outlet_id)
  REFERENCES merchant_auth_links(account_id,user_id,legacy_outlet_id),
 FOREIGN KEY(created_by) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(activated_by) REFERENCES merchant_staff(user_id),
 CHECK(business_type IN ('restaurant','cafe')),
 CHECK(version>0 AND created_epoch>0),
 CHECK((status='pending_activation' AND activated_by IS NULL AND activated_epoch IS NULL AND activation_request_id IS NULL)
    OR (status='active' AND activated_by IS NOT NULL AND activated_epoch IS NOT NULL AND activation_request_id IS NOT NULL))
) ENGINE=InnoDB;
CREATE TABLE merchant_provisioning_events (
 event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 UNIQUE KEY uq_m02a_event(merchant_id,operation),
 KEY ix_m02a_activation_rate(actor_user_id,operation,occurred_epoch),
 FOREIGN KEY(merchant_id) REFERENCES merchant_onboarding(merchant_id),
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 CHECK(operation IN ('provisioned','activated'))
) ENGINE=InnoDB;
