-- Additive scheduling, immutable status events, and durable post-commit notifications.
-- Existing appointments and financial/service values are not rewritten.
ALTER TABLE stations ADD COLUMN BookingCapacity SMALLINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN HomeCapacity SMALLINT UNSIGNED NULL DEFAULT NULL, ADD COLUMN DefaultServiceMinutes SMALLINT UNSIGNED NOT NULL DEFAULT 30, ADD COLUMN DeliveryBufferMinutes SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN StationFee DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN HomeFee DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN DeliveryFee DECIMAL(10,2) NOT NULL DEFAULT 0, ADD CONSTRAINT chk_booking_capacity CHECK (BookingCapacity IS NULL OR BookingCapacity BETWEEN 1 AND 1000), ADD CONSTRAINT chk_home_capacity CHECK (HomeCapacity IS NULL OR HomeCapacity BETWEEN 1 AND 1000), ADD CONSTRAINT chk_service_minutes CHECK (DefaultServiceMinutes BETWEEN 1 AND 1440), ADD CONSTRAINT chk_delivery_buffer CHECK (DeliveryBufferMinutes BETWEEN 0 AND 1440), ADD CONSTRAINT chk_station_fees CHECK (StationFee >= 0 AND HomeFee >= 0 AND DeliveryFee >= 0);
ALTER TABLE booking ADD COLUMN DurationMinutes SMALLINT UNSIGNED NULL, ADD COLUMN ScheduledEndAtUtc DATETIME NULL, ADD COLUMN ServicesSubtotal DECIMAL(10,2) NULL, ADD COLUMN AdditionalFee DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN RequestFingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD COLUMN ActiveRequestFingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN Status IN ('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning') THEN RequestFingerprint ELSE NULL END) STORED, ADD UNIQUE KEY uq_active_booking_intent (ActiveRequestFingerprint), ADD KEY idx_booking_capacity (StationID,Status,ScheduledAtUtc,ScheduledEndAtUtc);
ALTER TABLE booking_services ADD COLUMN DurationMinutes SMALLINT UNSIGNED NULL;
CREATE TABLE booking_status_history (
    EventID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    BookingID INT NOT NULL,
    FromStatus VARCHAR(32) NULL,
    ToStatus VARCHAR(32) NOT NULL,
    ActorID INT NULL,
    ActorRole ENUM('customer','owner','driver','admin','system') NOT NULL,
    Reason VARCHAR(500) NOT NULL,
    ChangedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (EventID),
    KEY idx_booking_status_history (BookingID,EventID),
    CONSTRAINT fk_status_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_status_actor FOREIGN KEY (ActorID) REFERENCES user (UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE notification_outbox (
    OutboxID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID INT NULL,
    TargetRole VARCHAR(50) NULL,
    Title VARCHAR(255) NOT NULL,
    TitleEn VARCHAR(255) NOT NULL,
    Body TEXT NOT NULL,
    BodyEn TEXT NOT NULL,
    Type VARCHAR(20) NOT NULL,
    TargetUrl VARCHAR(255) NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    DeliveredAt DATETIME(6) NULL,
    NotificationID INT NULL,
    PRIMARY KEY (OutboxID),
    KEY idx_notification_delivery (DeliveredAt,OutboxID),
    CONSTRAINT fk_outbox_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT fk_outbox_notification FOREIGN KEY (NotificationID) REFERENCES notifications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
