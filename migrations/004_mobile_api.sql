-- Additive REST API persistence. No legacy rows are changed or fabricated.
CREATE TABLE saved_addresses (
    AddressID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID INT NOT NULL,
    Label VARCHAR(80) NOT NULL,
    AddressText VARCHAR(2000) NOT NULL,
    Latitude DECIMAL(10,8) NOT NULL,
    Longitude DECIMAL(11,8) NOT NULL,
    IsDefault TINYINT(1) NOT NULL DEFAULT 0,
    ArchivedAt DATETIME(6) NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    DefaultUserID INT GENERATED ALWAYS AS (CASE WHEN IsDefault=1 AND ArchivedAt IS NULL THEN UserID ELSE NULL END) STORED,
    PRIMARY KEY (AddressID),
    UNIQUE KEY uq_address_default (DefaultUserID),
    KEY idx_address_user (UserID, ArchivedAt, AddressID),
    CONSTRAINT fk_address_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT chk_address_lat CHECK (Latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_address_lon CHECK (Longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE payment_sessions (
    SessionID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UserID INT NOT NULL,
    BookingID INT NOT NULL,
    Method ENUM('wallet','cash','plan') NOT NULL,
    UsePoints TINYINT(1) NOT NULL DEFAULT 0,
    QuotedAmount DECIMAL(10,2) NOT NULL,
    QuotedPoints DECIMAL(10,2) NOT NULL,
    Status ENUM('created','confirmed') NOT NULL DEFAULT 'created',
    PaymentID BIGINT UNSIGNED NULL,
    ExpiresAt DATETIME(6) NOT NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (SessionID),
    KEY idx_session_user (UserID, CreatedAt),
    CONSTRAINT fk_payment_session_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_session_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_session_receipt FOREIGN KEY (PaymentID) REFERENCES payments (PaymentID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE refund_requests (
    RefundID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID INT NOT NULL,
    BookingID INT NOT NULL,
    Reason VARCHAR(2000) NOT NULL,
    Status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    DecisionNote VARCHAR(2000) NULL,
    ProcessedBy INT NULL,
    CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ProcessedAt DATETIME(6) NULL,
    PRIMARY KEY (RefundID),
    UNIQUE KEY uq_refund_request_booking (BookingID),
    KEY idx_refund_queue (Status, CreatedAt),
    KEY idx_refund_user (UserID, RefundID),
    CONSTRAINT fk_refund_request_user FOREIGN KEY (UserID) REFERENCES user (UserID) ON DELETE RESTRICT,
    CONSTRAINT fk_refund_request_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT,
    CONSTRAINT fk_refund_processor FOREIGN KEY (ProcessedBy) REFERENCES user (UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE support_tickets ADD COLUMN Category ENUM('support','dispute') NOT NULL DEFAULT 'support', ADD COLUMN BookingID INT NULL, ADD KEY idx_ticket_inbox (user_id, status, id), ADD KEY idx_ticket_admin (Category, status, id), ADD CONSTRAINT fk_ticket_booking FOREIGN KEY (BookingID) REFERENCES booking (BookingID) ON DELETE RESTRICT;
ALTER TABLE booking ADD COLUMN DriverAcceptedAt DATETIME(6) NULL;
