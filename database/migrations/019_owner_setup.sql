ALTER TABLE merchant_onboarding
 ADD UNIQUE KEY uq_m02b_owner_scope(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id);
CREATE TABLE merchant_owner_setup (
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_outlet_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 legacy_outlet_id BIGINT UNSIGNED NOT NULL,
 owner_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 business_draft LONGTEXT NOT NULL,
 outlet_draft LONGTEXT NOT NULL,
 business_saved TINYINT NOT NULL DEFAULT 0,
 outlet_saved TINYINT NOT NULL DEFAULT 0,
 progress VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NOT_STARTED',
 legal_verification VARCHAR(16) NOT NULL DEFAULT 'UNVERIFIED',
 version BIGINT NOT NULL DEFAULT 1,
 created_epoch BIGINT NOT NULL,
 initialized_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_epoch BIGINT NOT NULL,
 submitted_epoch BIGINT NULL,
 reviewed_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reviewed_epoch BIGINT NULL,
 review_note VARCHAR(500) NOT NULL DEFAULT '',
 FOREIGN KEY(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id)
  REFERENCES merchant_onboarding(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id),
 FOREIGN KEY(reviewed_by) REFERENCES merchant_staff(user_id),
 FOREIGN KEY(initialized_by) REFERENCES merchant_staff(user_id),
 CHECK(JSON_VALID(business_draft) AND JSON_VALID(outlet_draft)),
 CHECK(business_saved IN(0,1) AND outlet_saved IN(0,1) AND version>0),
 CHECK(progress IN ('NOT_STARTED','IN_PROGRESS','SETUP_COMPLETE','APPROVAL_PENDING','APPROVED','REJECTED')),
 CHECK(legal_verification='UNVERIFIED')
) ENGINE=InnoDB;
CREATE TABLE merchant_setup_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 merchant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 from_status VARCHAR(24) NOT NULL,
 to_status VARCHAR(24) NOT NULL,
 result_version BIGINT NOT NULL,
 occurred_epoch BIGINT NOT NULL,
 FOREIGN KEY(merchant_id) REFERENCES merchant_owner_setup(merchant_id),
 FOREIGN KEY(actor_user_id) REFERENCES merchant_staff(user_id),
 CHECK(operation IN ('save_business','save_outlet','submit','reopen','review')),
 KEY ix_m02b_audit(merchant_id,occurred_epoch)
) ENGINE=InnoDB;
