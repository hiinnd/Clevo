-- Additive only. Run through scripts/migrate-database.php after 001 and preflight.
ALTER TABLE wallet ADD UNIQUE KEY uq_wallet_user (UserID);
ALTER TABLE booking ADD UNIQUE KEY uq_booking_order_number (Order_Number);
ALTER TABLE booking ADD COLUMN ScheduledAtUtc DATETIME NULL, ADD COLUMN BookingTimeZone VARCHAR(64) NULL;
ALTER TABLE booking ADD COLUMN OwnerIDSnapshot INT NULL, ADD CONSTRAINT fk_booking_owner_snapshot FOREIGN KEY (OwnerIDSnapshot) REFERENCES user (UserID) ON DELETE RESTRICT;
ALTER TABLE booking ADD KEY idx_booking_user_status_created (UserID, Status, created_at), ADD KEY idx_booking_station_status_date (StationID, Status, Booking_Date), ADD KEY idx_booking_driver_status (DriverID, Status), ADD KEY idx_booking_expiry (Status, notification_sent, created_at);
ALTER TABLE stations ADD COLUMN ArchivedAt DATETIME(6) NULL, ADD KEY idx_station_catalog (admin_status, Status, ArchivedAt);
ALTER TABLE services ADD COLUMN ArchivedAt DATETIME(6) NULL, ADD KEY idx_service_catalog (StationID, ArchivedAt);
ALTER TABLE user_plans ADD COLUMN PurchasePrice DECIMAL(10,2) NULL, ADD COLUMN PurchasedWashes INT NULL, ADD COLUMN PlanNameSnapshot VARCHAR(255) NULL, ADD KEY idx_user_plan_active (user_id, status, end_date, created_at);
ALTER TABLE wallet_transactions ADD COLUMN OperationKey VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD COLUMN BookingID INT NULL, ADD UNIQUE KEY uq_wallet_operation (OperationKey), ADD KEY idx_wallet_history (WalletID, CreatedAt, TransactionID), ADD CONSTRAINT fk_wallet_transaction_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT;
ALTER TABLE notifications ADD KEY idx_notification_inbox (user_id, created_at, id), ADD KEY idx_notification_role (target_role, created_at, id);
ALTER TABLE support_messages ADD KEY idx_support_thread (TicketID, CreatedAt, MessageID), ADD CONSTRAINT fk_support_sender FOREIGN KEY (SenderID) REFERENCES user (UserID) ON DELETE RESTRICT;
ALTER TABLE owner_withdrawal_requests ADD KEY idx_withdrawal_queue (status, requested_at), ADD KEY idx_withdrawal_owner (owner_id, requested_at);
CREATE TABLE request_idempotency (
    UserID INT NOT NULL,
    Scope VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    KeyHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    RequestHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ResponseJson TEXT NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (UserID, Scope, KeyHash),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE payments (
    PaymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    BookingID INT NOT NULL,
    UserID INT NOT NULL,
    Method VARCHAR(20) NOT NULL,
    Status ENUM('pending','recorded','refunded') NOT NULL,
    Amount DECIMAL(10,2) NOT NULL,
    Currency CHAR(3) NOT NULL DEFAULT 'JOD',
    Provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ProviderReference VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    RefundedAt DATETIME(6) NULL,
    PRIMARY KEY (PaymentID),
    UNIQUE KEY uq_payment_booking (BookingID),
    UNIQUE KEY uq_payment_provider (Provider, ProviderReference),
    KEY idx_payment_user (UserID, CreatedAt),
    CONSTRAINT fk_payment_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_amount CHECK (Amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE booking_effects (
    BookingID INT NOT NULL,
    Effect VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (BookingID, Effect),
    CONSTRAINT fk_booking_effect FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE driver_assignments (
    AssignmentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    BookingID INT NOT NULL,
    DriverID INT NOT NULL,
    StationID INT NOT NULL,
    AssignedBy INT NOT NULL,
    AssignedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    EndedAt DATETIME(6) NULL,
    ActiveBookingID INT GENERATED ALWAYS AS (CASE WHEN EndedAt IS NULL THEN BookingID ELSE NULL END) STORED,
    PRIMARY KEY (AssignmentID),
    UNIQUE KEY uq_active_assignment (ActiveBookingID),
    KEY idx_assignment_booking (BookingID, AssignedAt),
    KEY idx_assignment_driver (DriverID, EndedAt),
    CONSTRAINT fk_assignment_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_driver FOREIGN KEY (DriverID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_station FOREIGN KEY (StationID) REFERENCES stations (StationID) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_actor FOREIGN KEY (AssignedBy) REFERENCES user (UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE tracking_records (
    TrackingID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    BookingID INT NOT NULL,
    DriverID INT NOT NULL,
    Latitude DECIMAL(10,8) NOT NULL,
    Longitude DECIMAL(11,8) NOT NULL,
    RecordedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (TrackingID),
    KEY idx_tracking_booking (BookingID, RecordedAt, TrackingID),
    CONSTRAINT fk_tracking_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_tracking_driver FOREIGN KEY (DriverID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT chk_tracking_lat CHECK (Latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_tracking_lon CHECK (Longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE audit_logs (
    AuditID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ActorID INT NULL,
    Action VARCHAR(60) NOT NULL,
    EntityType VARCHAR(40) NOT NULL,
    EntityID BIGINT UNSIGNED NOT NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (AuditID),
    KEY idx_audit_entity (EntityType, EntityID, CreatedAt),
    KEY idx_audit_actor (ActorID, CreatedAt),
    CONSTRAINT fk_audit_actor FOREIGN KEY (ActorID) REFERENCES user (UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
