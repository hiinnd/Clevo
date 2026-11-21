-- Additive, history-preserving account erasure workflow. No existing records are deleted.
CREATE TABLE account_deletion_requests (
 RequestID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 UserID INT NOT NULL,
 State ENUM('requested','cancelled','processing','completed') NOT NULL DEFAULT 'requested',
 RequestedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 CompletedAt DATETIME(6) NULL,
 ReviewedBy INT NULL,
 ReviewReference VARCHAR(100) NULL,
 PolicyVersion VARCHAR(40) NULL,
 PRIMARY KEY(RequestID), UNIQUE KEY uq_deletion_user(UserID), KEY idx_deletion_queue(State,RequestedAt),
 CONSTRAINT fk_deletion_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT fk_deletion_reviewer FOREIGN KEY(ReviewedBy) REFERENCES user(UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE account_erasure_files (
 FileID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 RequestID BIGINT UNSIGNED NOT NULL,
 StorageReference VARCHAR(255) NOT NULL,
 State ENUM('queued','deleted') NOT NULL DEFAULT 'queued',
 Attempts INT UNSIGNED NOT NULL DEFAULT 0,
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(FileID), UNIQUE KEY uq_erasure_file(RequestID,StorageReference), KEY idx_erasure_file_state(State,FileID),
 CONSTRAINT fk_erasure_file_request FOREIGN KEY(RequestID) REFERENCES account_deletion_requests(RequestID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Newly queued security messages can be cancelled during erasure without guessing encrypted recipients.
ALTER TABLE security_mail_queue ADD COLUMN UserID INT NULL, ADD KEY idx_security_mail_user(UserID,SentAt), ADD CONSTRAINT fk_security_mail_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT;
