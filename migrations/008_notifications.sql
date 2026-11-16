-- Forward-only fix: a new driving phase must not reuse a pickup GPS projection.
ALTER TABLE booking ADD COLUMN DriverLocationEpochUtc DATETIME(6) NULL;
ALTER TABLE security_mail_queue ADD COLUMN LeaseUntil DATETIME(6) NULL, ADD COLUMN Claim CHAR(32) NULL, ADD COLUMN LastError VARCHAR(40) NULL;
CREATE TABLE notification_preferences (
 UserID INT NOT NULL, Language ENUM('ar','en') NOT NULL DEFAULT 'ar', PushEnabled TINYINT(1) NOT NULL DEFAULT 1, EmailEnabled TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY(UserID), CONSTRAINT fk_preferences_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE mobile_devices (
 DeviceID CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, UserID INT NOT NULL, SessionID CHAR(64) NOT NULL,
 TokenHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, TokenCipher TEXT NULL, Platform ENUM('android','ios') NOT NULL, UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), RevokedAt DATETIME(6) NULL,
 PRIMARY KEY(DeviceID), UNIQUE KEY uq_device_token(TokenHash), KEY idx_device_user(UserID,RevokedAt),
 CONSTRAINT fk_device_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE notification_deliveries (
 DeliveryID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, NotificationID INT NOT NULL, UserID INT NOT NULL, DeviceID CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 Channel ENUM('push','email') NOT NULL, DedupKey CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, State ENUM('queued','sending','sent','skipped','dead') NOT NULL DEFAULT 'queued',
 Attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, AvailableAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), LeaseUntil DATETIME(6) NULL, Claim CHAR(32) NULL, LastError VARCHAR(40) NULL, SentAt DATETIME(6) NULL,
 PRIMARY KEY(DeliveryID), UNIQUE KEY uq_delivery_intent(DedupKey), KEY idx_delivery_due(State,AvailableAt),
 CONSTRAINT fk_delivery_notification FOREIGN KEY(NotificationID) REFERENCES notifications(id) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_device FOREIGN KEY(DeviceID) REFERENCES mobile_devices(DeviceID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
