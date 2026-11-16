-- Additive logistics state and short-lived GPS metadata. No historical rows are rewritten.
ALTER TABLE booking ADD COLUMN DeliveryWorkflowVersion SMALLINT UNSIGNED NULL, ADD COLUMN DeliveryStage VARCHAR(32) NULL;
ALTER TABLE driver_assignments ADD COLUMN AcceptedAt DATETIME(6) NULL, ADD COLUMN LastSequence BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN LastCapturedAt DATETIME(6) NULL, ADD COLUMN LastSampleHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD COLUMN ActiveDriverID INT GENERATED ALWAYS AS (CASE WHEN EndedAt IS NULL AND AcceptedAt IS NOT NULL THEN DriverID ELSE NULL END) STORED, ADD UNIQUE KEY uq_driver_execution (ActiveDriverID);
ALTER TABLE tracking_records ADD COLUMN AssignmentID BIGINT UNSIGNED NULL, ADD COLUMN SequenceNumber BIGINT UNSIGNED NULL, ADD COLUMN CapturedAt DATETIME(6) NULL, ADD COLUMN AccuracyMeters DECIMAL(8,2) NULL, ADD COLUMN CaptureMode ENUM('foreground','background') NULL, ADD COLUMN SampleHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY uq_tracking_sequence (AssignmentID,SequenceNumber), ADD KEY idx_tracking_retention (RecordedAt,TrackingID), ADD CONSTRAINT fk_tracking_assignment FOREIGN KEY (AssignmentID) REFERENCES driver_assignments(AssignmentID) ON DELETE RESTRICT, ADD CONSTRAINT chk_tracking_accuracy CHECK (AccuracyMeters IS NULL OR AccuracyMeters BETWEEN 0 AND 5000);
CREATE TABLE delivery_events (
 EventID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 BookingID INT NOT NULL,
 AssignmentID BIGINT UNSIGNED NOT NULL,
 FromStage VARCHAR(32) NULL,
 ToStage VARCHAR(32) NOT NULL,
 ActorID INT NULL,
 ActorRole ENUM('owner','driver','system','admin') NOT NULL,
 Reason VARCHAR(80) NOT NULL,
 ChangedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(EventID), KEY idx_delivery_history(BookingID,EventID),
 CONSTRAINT fk_delivery_booking FOREIGN KEY(BookingID) REFERENCES booking(BookingID) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_assignment FOREIGN KEY(AssignmentID) REFERENCES driver_assignments(AssignmentID) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_actor FOREIGN KEY(ActorID) REFERENCES user(UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE delivery_confirmations (
 ConfirmationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 AssignmentID BIGINT UNSIGNED NOT NULL,
 Kind ENUM('pickup','delivery') NOT NULL,
 Nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 CodeHash VARCHAR(255) NULL,
 Attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 IssuedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 ExpiresAt DATETIME(6) NOT NULL,
 ConsumedAt DATETIME(6) NULL,
 PRIMARY KEY(ConfirmationID), UNIQUE KEY uq_delivery_confirmation(AssignmentID,Kind), KEY idx_confirmation_expiry(ExpiresAt),
 CONSTRAINT fk_confirmation_assignment FOREIGN KEY(AssignmentID) REFERENCES driver_assignments(AssignmentID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
