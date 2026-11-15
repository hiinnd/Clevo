-- Additive authentication schema. Execute only through scripts/migrate-security.php.
ALTER TABLE `user` ADD COLUMN AuthVersion INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `user` ADD COLUMN EmailVerifiedAt DATETIME NULL;
ALTER TABLE `user` ADD COLUMN MustResetPassword TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `user` ADD COLUMN PendingEmail VARCHAR(100) NULL;
ALTER TABLE booking ADD COLUMN DriverAccessCodeHash VARCHAR(255) NULL;
ALTER TABLE booking ADD COLUMN DriverAccessCodeExpiresAt DATETIME NULL;
ALTER TABLE booking ADD COLUMN DriverAccessCodeUsedAt DATETIME NULL;

CREATE TABLE auth_sessions (
    SessionID CHAR(64) NOT NULL PRIMARY KEY,
    UserID INT(11) NOT NULL,
    AuthVersion INT UNSIGNED NOT NULL,
    RoleSnapshot VARCHAR(20) NOT NULL,
    DriverBookingID INT(11) NULL,
    ExpiresAt DATETIME NOT NULL,
    RevokedAt DATETIME NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auth_sessions_user (UserID),
    CONSTRAINT auth_sessions_user_fk FOREIGN KEY (UserID) REFERENCES `user` (UserID) ON DELETE CASCADE,
    CONSTRAINT auth_sessions_booking_fk FOREIGN KEY (DriverBookingID) REFERENCES booking (BookingID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_refresh_tokens (
    TokenHash CHAR(64) NOT NULL PRIMARY KEY,
    SessionID CHAR(64) NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    UsedAt DATETIME NULL,
    RevokedAt DATETIME NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_refresh_session (SessionID),
    CONSTRAINT refresh_session_fk FOREIGN KEY (SessionID) REFERENCES auth_sessions (SessionID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_challenges (
    ChallengeID CHAR(64) NOT NULL PRIMARY KEY,
    UserID INT(11) NOT NULL,
    Purpose VARCHAR(20) NOT NULL,
    SecretHash CHAR(64) NOT NULL,
    TargetEmail VARCHAR(100) NULL,
    Attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ExpiresAt DATETIME NOT NULL,
    ConsumedAt DATETIME NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_challenge_user (UserID, Purpose),
    CONSTRAINT challenge_user_fk FOREIGN KEY (UserID) REFERENCES `user` (UserID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_rate_limits (
    BucketKey CHAR(64) NOT NULL,
    WindowStart BIGINT NOT NULL,
    Hits INT UNSIGNED NOT NULL,
    ExpiresAt BIGINT NOT NULL,
    PRIMARY KEY (BucketKey, WindowStart),
    KEY idx_rate_expiry (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_reads (
    NotificationID INT(11) NOT NULL,
    UserID INT(11) NOT NULL,
    ReadAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (NotificationID, UserID),
    CONSTRAINT notification_reads_notification_fk FOREIGN KEY (NotificationID) REFERENCES notifications (id) ON DELETE CASCADE,
    CONSTRAINT notification_reads_user_fk FOREIGN KEY (UserID) REFERENCES `user` (UserID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_mail_queue (
    ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    PayloadCipher MEDIUMTEXT NOT NULL,
    Attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    AvailableAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    SentAt DATETIME NULL,
    KEY idx_mail_ready (SentAt, AvailableAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO notification_reads (NotificationID, UserID)
SELECT n.id, u.UserID FROM notifications n JOIN `user` u ON LOWER(u.Role) = LOWER(n.target_role)
WHERE n.user_id IS NULL AND n.is_read = 1;

-- Existing active accounts keep their verified state. Legacy password formats and known
-- shared/demo hashes are invalidated by the migration runner without disclosing values.
UPDATE `user` SET EmailVerifiedAt = UTC_TIMESTAMP() WHERE AccountStatus = 'active' AND Email IS NOT NULL;
UPDATE `user` SET VerificationToken = NULL, reset_otp = NULL, reset_otp_expiry = NULL, CurrentAccessCode = NULL;
UPDATE booking SET DriverAccessCode = NULL;
