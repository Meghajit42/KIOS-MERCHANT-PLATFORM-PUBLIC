CREATE TABLE merchant_api_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 window_epoch BIGINT NOT NULL, requests INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
CREATE TABLE merchant_api_events (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 route_code VARCHAR(32) NOT NULL, status_code SMALLINT UNSIGNED NOT NULL,
 scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_epoch BIGINT NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES merchant_staff(user_id)
) ENGINE=InnoDB;
CREATE TRIGGER api_event_no_update BEFORE UPDATE ON merchant_api_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='API events are immutable';
CREATE TRIGGER api_event_no_delete BEFORE DELETE ON merchant_api_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='API events are immutable';
