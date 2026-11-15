-- Additive only. Existing monetary columns, balances, commissions and evidence remain unchanged.
-- New JOD accounting uses integer fils (1 JOD = 1000), not legacy hundredths.
ALTER TABLE booking ADD COLUMN FinancialVersion SMALLINT UNSIGNED NULL, ADD COLUMN CommissionRateBps SMALLINT UNSIGNED NULL, ADD COLUMN CommissionBasisMinor BIGINT UNSIGNED NULL, ADD COLUMN CommissionMinor BIGINT UNSIGNED NULL, ADD COLUMN OwnerAmountMinor BIGINT UNSIGNED NULL;
ALTER TABLE plans ADD COLUMN SandboxPolicy JSON NULL;
CREATE TABLE payment_orders (
 OrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UserID INT NOT NULL,
 BookingID INT NULL,
 PlanID INT NULL,
 Purpose ENUM('booking','subscription') NOT NULL,
 Provider VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 Environment ENUM('sandbox') NOT NULL,
 State ENUM('prepared','creating','pending','succeeded','failed','cancelled','unknown','refund_due','refunded') NOT NULL DEFAULT 'prepared',
 AmountMinor BIGINT UNSIGNED NOT NULL,
 Currency CHAR(3) NOT NULL DEFAULT 'JOD',
 ProviderReference VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NULL,
 CheckoutCipher TEXT NULL,
 TermsSnapshot JSON NULL,
 IntentKey VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ActiveIntent VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN State IN ('prepared','creating','pending','unknown','refund_due') THEN IntentKey ELSE NULL END) STORED,
 CancelRequestedAt DATETIME(6) NULL,
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 ExpiresAt DATETIME(6) NOT NULL,
 VerifiedAt DATETIME(6) NULL,
 PRIMARY KEY(OrderID),
 UNIQUE KEY uq_order_intent(ActiveIntent),
 UNIQUE KEY uq_order_provider_reference(Provider,Environment,ProviderReference),
 KEY idx_order_user(UserID,CreatedAt),
 KEY idx_order_reconciliation(State,UpdatedAt),
 CONSTRAINT fk_order_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT fk_order_booking FOREIGN KEY(BookingID) REFERENCES booking(BookingID) ON DELETE RESTRICT,
 CONSTRAINT fk_order_plan FOREIGN KEY(PlanID) REFERENCES plans(id) ON DELETE RESTRICT,
 CONSTRAINT chk_order_target CHECK((Purpose='booking' AND BookingID IS NOT NULL AND PlanID IS NULL) OR (Purpose='subscription' AND PlanID IS NOT NULL AND BookingID IS NULL)),
 CONSTRAINT chk_order_amount CHECK(AmountMinor>0 AND AmountMinor<=99999999999 AND Currency='JOD')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE payments ADD COLUMN FundingOrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD COLUMN VerificationKind VARCHAR(40) NULL, ADD COLUMN VerifiedAt DATETIME(6) NULL, ADD UNIQUE KEY uq_receipt_funding(FundingOrderID), ADD CONSTRAINT fk_receipt_funding FOREIGN KEY(FundingOrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT;
ALTER TABLE user_plans ADD COLUMN FundingOrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD COLUMN TermsSnapshot JSON NULL, ADD COLUMN PaymentHold TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN StartsAtUtc DATETIME(6) NULL, ADD COLUMN ExpiresAtUtc DATETIME(6) NULL, ADD UNIQUE KEY uq_subscription_funding(FundingOrderID), ADD CONSTRAINT fk_subscription_funding FOREIGN KEY(FundingOrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT;
CREATE TABLE subscription_redemptions (
 RedemptionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 UserPlanID INT NOT NULL,
 BookingID INT NOT NULL,
 SlotNumber INT NOT NULL,
 AmountMinor BIGINT UNSIGNED NOT NULL,
 State ENUM('reserved','completed','released') NOT NULL DEFAULT 'reserved',
 ActiveSlot INT GENERATED ALWAYS AS (CASE WHEN State<>'released' THEN SlotNumber ELSE NULL END) STORED,
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(RedemptionID),
 UNIQUE KEY uq_redemption_booking(BookingID),
 UNIQUE KEY uq_redemption_slot(UserPlanID,ActiveSlot),
 CONSTRAINT fk_redemption_plan FOREIGN KEY(UserPlanID) REFERENCES user_plans(id) ON DELETE RESTRICT,
 CONSTRAINT fk_redemption_booking FOREIGN KEY(BookingID) REFERENCES booking(BookingID) ON DELETE RESTRICT,
 CONSTRAINT chk_redemption_amount CHECK(AmountMinor>0 AND SlotNumber>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE payment_evidence (
 EvidenceID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 OrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 EventHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 Source ENUM('signed_webhook','authenticated_query') NOT NULL,
 ProviderReference VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 TransactionType VARCHAR(20) NOT NULL,
 ResultCode VARCHAR(8) NOT NULL,
 AmountMinor BIGINT UNSIGNED NOT NULL,
 ReceivedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(EvidenceID), UNIQUE KEY uq_evidence_event(EventHash), KEY idx_evidence_order(OrderID,EvidenceID),
 CONSTRAINT fk_evidence_order FOREIGN KEY(OrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE financial_journals (
 JournalID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 OperationKey VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 Kind VARCHAR(40) NOT NULL,
 ContentHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 Book ENUM('sandbox','cash','legacy_fixture') NOT NULL,
 BookingID INT NULL,
 OrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
 OwnerID INT NULL,
 GrossMinor BIGINT UNSIGNED NULL,
 CommissionMinor BIGINT UNSIGNED NULL,
 OwnerMinor BIGINT UNSIGNED NULL,
 DebitMinor BIGINT UNSIGNED NOT NULL,
 CreditMinor BIGINT UNSIGNED NOT NULL,
 Currency CHAR(3) NOT NULL DEFAULT 'JOD',
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(JournalID), UNIQUE KEY uq_journal_operation(OperationKey), KEY idx_journal_owner(OwnerID,Kind,CreatedAt),
 CONSTRAINT fk_journal_booking FOREIGN KEY(BookingID) REFERENCES booking(BookingID) ON DELETE RESTRICT,
 CONSTRAINT fk_journal_order FOREIGN KEY(OrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT,
 CONSTRAINT fk_journal_owner FOREIGN KEY(OwnerID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT chk_journal_balanced CHECK(DebitMinor=CreditMinor AND DebitMinor>0 AND Currency='JOD')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE financial_entries (
 EntryID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 JournalID BIGINT UNSIGNED NOT NULL,
 AccountKey VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 SignedMinor BIGINT NOT NULL,
 PRIMARY KEY(EntryID), KEY idx_entry_journal(JournalID), KEY idx_entry_account(AccountKey,EntryID),
 CONSTRAINT fk_entry_journal FOREIGN KEY(JournalID) REFERENCES financial_journals(JournalID) ON DELETE RESTRICT,
 CONSTRAINT chk_entry_nonzero CHECK(SignedMinor<>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE financial_receipts (
 ReceiptID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 ReceiptNumber VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 JournalID BIGINT UNSIGNED NOT NULL,
 UserID INT NOT NULL,
 BookingID INT NULL,
 OrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
 AmountMinor BIGINT UNSIGNED NOT NULL,
 EvidenceKind VARCHAR(40) NOT NULL,
 IssuedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(ReceiptID), UNIQUE KEY uq_financial_receipt_number(ReceiptNumber), UNIQUE KEY uq_financial_receipt_journal(JournalID), KEY idx_receipt_user(UserID,ReceiptID),
 CONSTRAINT fk_fin_receipt_journal FOREIGN KEY(JournalID) REFERENCES financial_journals(JournalID) ON DELETE RESTRICT,
 CONSTRAINT fk_fin_receipt_user FOREIGN KEY(UserID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT fk_fin_receipt_booking FOREIGN KEY(BookingID) REFERENCES booking(BookingID) ON DELETE RESTRICT,
 CONSTRAINT fk_fin_receipt_order FOREIGN KEY(OrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE provider_refunds (
 RefundID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 OrderID CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 RequestedBy INT NOT NULL,
 Origin ENUM('customer_request','provider_notification') NOT NULL DEFAULT 'customer_request',
 Reason VARCHAR(500) NOT NULL,
 State ENUM('requested','submitting','pending','succeeded','failed','unknown') NOT NULL DEFAULT 'requested',
 AmountMinor BIGINT UNSIGNED NOT NULL,
 ProviderReference VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NULL,
 SubmittedBy INT NULL,
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UpdatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(RefundID), UNIQUE KEY uq_provider_refund_order(OrderID), UNIQUE KEY uq_provider_refund_reference(ProviderReference),
 CONSTRAINT fk_provider_refund_order FOREIGN KEY(OrderID) REFERENCES payment_orders(OrderID) ON DELETE RESTRICT,
 CONSTRAINT fk_provider_refund_actor FOREIGN KEY(RequestedBy) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT fk_provider_refund_admin FOREIGN KEY(SubmittedBy) REFERENCES user(UserID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE financial_settlements (
 SettlementID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 OwnerID INT NOT NULL,
 AmountMinor BIGINT UNSIGNED NOT NULL,
 State ENUM('blocked','pending','verified','cancelled') NOT NULL DEFAULT 'blocked',
 ProviderReference VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NULL,
 EvidenceHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 CreatedAt DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(SettlementID), UNIQUE KEY uq_settlement_reference(ProviderReference), KEY idx_settlement_owner(OwnerID,SettlementID),
 CONSTRAINT fk_settlement_owner FOREIGN KEY(OwnerID) REFERENCES user(UserID) ON DELETE RESTRICT,
 CONSTRAINT chk_settlement_evidence CHECK(State<>'verified' OR (ProviderReference IS NOT NULL AND EvidenceHash IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
