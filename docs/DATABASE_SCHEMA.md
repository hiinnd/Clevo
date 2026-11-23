# CLEVO schema inventory

Generated from actual migrated disposable metadata on 2026-11-23. Engine: `10.4.32-MariaDB`; session timezone: `+00:00`. Tables: 52; columns: 521; index columns: 267; foreign-key columns: 89. This is not a live production inventory.

Source: `scripts/database-inventory.php` output after the applied versioned migrations (including booking005,payments006,tracking007,notifications008,privacy009,cleanup010 and idempotency security011 when present). Reproduce with `php scripts/render-schema-docs.php <protected-inventory.json>`. No customer data is included; sensitive defaults are redacted. Existing FK delete rules are preserved when present, so a deployed variant may differ from this copy. Read [DATABASE_CHANGES.md](DATABASE_CHANGES.md), [BOOKING_WORKFLOW.md](BOOKING_WORKFLOW.md) and [PAYMENT_INTEGRATION.md](PAYMENT_INTEGRATION.md) for semantics, anomalies, compatibility and release gates; [DATABASE_ERD.md](DATABASE_ERD.md) for the graph.

## `account_deletion_requests`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `RequestID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `State` | `enum('requested','cancelled','processing','completed')` | NO | `'requested'` |  |
| `RequestedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` | on update current_timestamp(6) |
| `CompletedAt` | `datetime(6)` | YES | `NULL` |  |
| `ReviewedBy` | `int(11)` | YES | `NULL` |  |
| `ReviewReference` | `varchar(100)` | YES | `NULL` |  |
| `PolicyVersion` | `varchar(40)` | YES | `NULL` |  |
| `LocationErasedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_deletion_reviewer` | INDEX | `ReviewedBy` |
| `idx_deletion_queue` | INDEX | `State`, `RequestedAt` |
| `PRIMARY` | PRIMARY | `RequestID` |
| `uq_deletion_user` | UNIQUE | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_deletion_reviewer` | `ReviewedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_deletion_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `account_erasure_files`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `FileID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `RequestID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `StorageReference` | `varchar(255)` | NO | `NULL` |  |
| `State` | `enum('queued','deleted')` | NO | `'queued'` |  |
| `Attempts` | `int(10) unsigned` | NO | `0` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_erasure_file_state` | INDEX | `State`, `FileID` |
| `PRIMARY` | PRIMARY | `FileID` |
| `uq_erasure_file` | UNIQUE | `RequestID`, `StorageReference` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_erasure_file_request` | `RequestID` | `account_deletion_requests.RequestID` | RESTRICT / RESTRICT |

## `audit_logs`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `AuditID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `ActorID` | `int(11)` | YES | `NULL` |  |
| `Action` | `varchar(60)` | NO | `NULL` |  |
| `EntityType` | `varchar(40)` | NO | `NULL` |  |
| `EntityID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_audit_actor` | INDEX | `ActorID`, `CreatedAt` |
| `idx_audit_entity` | INDEX | `EntityType`, `EntityID`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `AuditID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_audit_actor` | `ActorID` | `user.UserID` | RESTRICT / RESTRICT |

## `auth_challenges`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ChallengeID` | `char(64)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `Purpose` | `varchar(20)` | NO | `NULL` |  |
| `SecretHash` | `char(64)` | NO | `NULL` |  |
| `TargetEmail` | `varchar(100)` | YES | `NULL` |  |
| `Attempts` | `smallint(5) unsigned` | NO | `0` |  |
| `ExpiresAt` | `datetime` | NO | `NULL` |  |
| `ConsumedAt` | `datetime` | YES | `NULL` |  |
| `CreatedAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_challenge_user` | INDEX | `UserID`, `Purpose` |
| `PRIMARY` | PRIMARY | `ChallengeID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `challenge_user_fk` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `auth_refresh_tokens`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `TokenHash` | `char(64)` | NO | `NULL` |  |
| `SessionID` | `char(64)` | NO | `NULL` |  |
| `ExpiresAt` | `datetime` | NO | `NULL` |  |
| `UsedAt` | `datetime` | YES | `NULL` |  |
| `RevokedAt` | `datetime` | YES | `NULL` |  |
| `CreatedAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_refresh_session` | INDEX | `SessionID` |
| `PRIMARY` | PRIMARY | `TokenHash` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `refresh_session_fk` | `SessionID` | `auth_sessions.SessionID` | CASCADE / RESTRICT |

## `auth_sessions`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `SessionID` | `char(64)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `AuthVersion` | `int(10) unsigned` | NO | `NULL` |  |
| `RoleSnapshot` | `varchar(20)` | NO | `NULL` |  |
| `DriverBookingID` | `int(11)` | YES | `NULL` |  |
| `ExpiresAt` | `datetime` | NO | `NULL` |  |
| `RevokedAt` | `datetime` | YES | `NULL` |  |
| `CreatedAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `auth_sessions_booking_fk` | INDEX | `DriverBookingID` |
| `idx_auth_sessions_user` | INDEX | `UserID` |
| `PRIMARY` | PRIMARY | `SessionID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `auth_sessions_booking_fk` | `DriverBookingID` | `booking.BookingID` | CASCADE / RESTRICT |
| `auth_sessions_user_fk` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `blacklist`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `user_id` | `int(11)` | YES | `NULL` |  |
| `DeviceID` | `varchar(255)` | YES | `NULL` |  |
| `reason` | `text` | YES | `NULL` |  |
| `created_at` | `timestamp` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `id` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `blacklist_ibfk_1` | `user_id` | `user.UserID` | CASCADE / RESTRICT |

## `booking`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `BookingID` | `int(11)` | NO | `NULL` | auto_increment |
| `Order_Number` | `varchar(20)` | YES | `NULL` |  |
| `UserID` | `int(11)` | YES | `NULL` |  |
| `StationID` | `int(11)` | YES | `NULL` |  |
| `ServiceID` | `int(11)` | YES | `NULL` |  |
| `DriverID` | `int(11)` | YES | `NULL` |  |
| `DriverAccessCode` | `varchar(10)` | YES | `[redacted]` |  |
| `Service_Location` | `enum('station','home','delivery')` | YES | `'station'` |  |
| `Latitude` | `decimal(10,8)` | YES | `NULL` |  |
| `Longitude` | `decimal(11,8)` | YES | `NULL` |  |
| `Driver_Lat` | `decimal(10,8)` | YES | `NULL` |  |
| `Driver_Lon` | `decimal(11,8)` | YES | `NULL` |  |
| `Address_Details` | `text` | YES | `NULL` |  |
| `Booking_Date` | `date` | YES | `NULL` |  |
| `Booking_Time` | `time` | YES | `NULL` |  |
| `Status` | `enum('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed')` | YES | `'Pending'` |  |
| `Total_Price` | `decimal(10,2)` | YES | `NULL` |  |
| `Admin_Commission` | `decimal(10,2)` | YES | `NULL` |  |
| `Payment_Method` | `varchar(50)` | YES | `NULL` |  |
| `Points_Used` | `decimal(10,2)` | YES | `0.00` |  |
| `Points_Discount` | `decimal(10,2)` | YES | `0.00` |  |
| `Payable_Amount` | `decimal(10,2)` | YES | `NULL` |  |
| `UserPlanID` | `int(11)` | YES | `NULL` |  |
| `Plan_Washes_Used` | `tinyint(1)` | YES | `0` |  |
| `Accepted_At` | `datetime` | YES | `NULL` |  |
| `notification_sent` | `tinyint(1)` | YES | `0` |  |
| `created_at` | `timestamp` | NO | `current_timestamp()` |  |
| `DriverAccessCodeHash` | `varchar(255)` | YES | `[redacted]` |  |
| `DriverAccessCodeExpiresAt` | `datetime` | YES | `[redacted]` |  |
| `DriverAccessCodeUsedAt` | `datetime` | YES | `[redacted]` |  |
| `ScheduledAtUtc` | `datetime` | YES | `NULL` |  |
| `BookingTimeZone` | `varchar(64)` | YES | `NULL` |  |
| `OwnerIDSnapshot` | `int(11)` | YES | `NULL` |  |
| `DriverAcceptedAt` | `datetime(6)` | YES | `NULL` |  |
| `DurationMinutes` | `smallint(5) unsigned` | YES | `NULL` |  |
| `ScheduledEndAtUtc` | `datetime` | YES | `NULL` |  |
| `ServicesSubtotal` | `decimal(10,2)` | YES | `NULL` |  |
| `AdditionalFee` | `decimal(10,2)` | NO | `0.00` |  |
| `RequestFingerprint` | `char(64)` | YES | `NULL` |  |
| `ActiveRequestFingerprint` | `char(64)` | YES | `NULL` | STORED GENERATED |
| `FinancialVersion` | `smallint(5) unsigned` | YES | `NULL` |  |
| `CommissionRateBps` | `smallint(5) unsigned` | YES | `NULL` |  |
| `CommissionBasisMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `CommissionMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `OwnerAmountMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `DeliveryWorkflowVersion` | `smallint(5) unsigned` | YES | `NULL` |  |
| `DeliveryStage` | `varchar(32)` | YES | `NULL` |  |
| `DriverLocationEpochUtc` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `DriverID` | INDEX | `DriverID` |
| `fk_booking_owner_snapshot` | INDEX | `OwnerIDSnapshot` |
| `idx_booking_capacity` | INDEX | `StationID`, `Status`, `ScheduledAtUtc`, `ScheduledEndAtUtc` |
| `idx_booking_driver_status` | INDEX | `DriverID`, `Status` |
| `idx_booking_expiry` | INDEX | `Status`, `notification_sent`, `created_at` |
| `idx_booking_station_status_date` | INDEX | `StationID`, `Status`, `Booking_Date` |
| `idx_booking_user_status_created` | INDEX | `UserID`, `Status`, `created_at` |
| `PRIMARY` | PRIMARY | `BookingID` |
| `ServiceID` | INDEX | `ServiceID` |
| `StationID` | INDEX | `StationID` |
| `uq_active_booking_intent` | UNIQUE | `ActiveRequestFingerprint` |
| `uq_booking_driver_access_code` | UNIQUE | `DriverAccessCode` |
| `uq_booking_order_number` | UNIQUE | `Order_Number` |
| `UserID` | INDEX | `UserID` |
| `UserPlanID` | INDEX | `UserPlanID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `booking_ibfk_1` | `UserID` | `user.UserID` | CASCADE / RESTRICT |
| `booking_ibfk_2` | `StationID` | `stations.StationID` | CASCADE / RESTRICT |
| `booking_ibfk_3` | `ServiceID` | `services.ServiceID` | CASCADE / RESTRICT |
| `booking_ibfk_4` | `DriverID` | `user.UserID` | SET NULL / RESTRICT |
| `booking_ibfk_5` | `UserPlanID` | `user_plans.id` | SET NULL / RESTRICT |
| `fk_booking_owner_snapshot` | `OwnerIDSnapshot` | `user.UserID` | RESTRICT / RESTRICT |

## `booking_effects`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `Effect` | `varchar(40)` | NO | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `BookingID`, `Effect` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_booking_effect` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |

## `booking_services`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `BookingServiceID` | `int(11)` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `ServiceID` | `int(11)` | YES | `NULL` |  |
| `ServiceName` | `varchar(255)` | YES | `NULL` |  |
| `Price` | `decimal(10,2)` | YES | `0.00` |  |
| `DurationMinutes` | `smallint(5) unsigned` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `BookingID` | INDEX | `BookingID` |
| `PRIMARY` | PRIMARY | `BookingServiceID` |
| `ServiceID` | INDEX | `ServiceID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `booking_services_booking_fk` | `BookingID` | `booking.BookingID` | CASCADE / RESTRICT |
| `booking_services_service_fk` | `ServiceID` | `services.ServiceID` | SET NULL / RESTRICT |

## `booking_status_history`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `EventID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `FromStatus` | `varchar(32)` | YES | `NULL` |  |
| `ToStatus` | `varchar(32)` | NO | `NULL` |  |
| `ActorID` | `int(11)` | YES | `NULL` |  |
| `ActorRole` | `enum('customer','owner','driver','admin','system')` | NO | `NULL` |  |
| `Reason` | `varchar(500)` | NO | `NULL` |  |
| `ChangedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_status_actor` | INDEX | `ActorID` |
| `idx_booking_status_history` | INDEX | `BookingID`, `EventID` |
| `PRIMARY` | PRIMARY | `EventID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_status_actor` | `ActorID` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_status_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |

## `database_migration_steps`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `Version` | `varchar(100)` | NO | `NULL` |  |
| `StepNumber` | `int(11)` | NO | `NULL` |  |
| `Checksum` | `char(64)` | NO | `NULL` |  |
| `State` | `enum('started','applied')` | NO | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `Version`, `StepNumber` |

No declared outbound foreign key.

## `delivery_confirmations`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ConfirmationID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `AssignmentID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `Kind` | `enum('pickup','delivery')` | NO | `NULL` |  |
| `Nonce` | `char(32)` | NO | `NULL` |  |
| `CodeHash` | `varchar(255)` | YES | `NULL` |  |
| `Attempts` | `smallint(5) unsigned` | NO | `0` |  |
| `IssuedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `ExpiresAt` | `datetime(6)` | NO | `NULL` |  |
| `ConsumedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_confirmation_expiry` | INDEX | `ExpiresAt` |
| `PRIMARY` | PRIMARY | `ConfirmationID` |
| `uq_delivery_confirmation` | UNIQUE | `AssignmentID`, `Kind` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_confirmation_assignment` | `AssignmentID` | `driver_assignments.AssignmentID` | RESTRICT / RESTRICT |

## `delivery_events`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `EventID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `AssignmentID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `FromStage` | `varchar(32)` | YES | `NULL` |  |
| `ToStage` | `varchar(32)` | NO | `NULL` |  |
| `ActorID` | `int(11)` | YES | `NULL` |  |
| `ActorRole` | `enum('owner','driver','system','admin')` | NO | `NULL` |  |
| `Reason` | `varchar(80)` | NO | `NULL` |  |
| `ChangedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_delivery_actor` | INDEX | `ActorID` |
| `fk_delivery_assignment` | INDEX | `AssignmentID` |
| `idx_delivery_history` | INDEX | `BookingID`, `EventID` |
| `PRIMARY` | PRIMARY | `EventID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_delivery_actor` | `ActorID` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_delivery_assignment` | `AssignmentID` | `driver_assignments.AssignmentID` | RESTRICT / RESTRICT |
| `fk_delivery_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |

## `driver_assignments`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `AssignmentID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `DriverID` | `int(11)` | NO | `NULL` |  |
| `StationID` | `int(11)` | NO | `NULL` |  |
| `AssignedBy` | `int(11)` | NO | `NULL` |  |
| `AssignedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `EndedAt` | `datetime(6)` | YES | `NULL` |  |
| `ActiveBookingID` | `int(11)` | YES | `NULL` | STORED GENERATED |
| `AcceptedAt` | `datetime(6)` | YES | `NULL` |  |
| `LastSequence` | `bigint(20) unsigned` | NO | `0` |  |
| `LastCapturedAt` | `datetime(6)` | YES | `NULL` |  |
| `LastSampleHash` | `char(64)` | YES | `NULL` |  |
| `ActiveDriverID` | `int(11)` | YES | `NULL` | STORED GENERATED |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_assignment_actor` | INDEX | `AssignedBy` |
| `fk_assignment_station` | INDEX | `StationID` |
| `idx_assignment_booking` | INDEX | `BookingID`, `AssignedAt` |
| `idx_assignment_driver` | INDEX | `DriverID`, `EndedAt` |
| `PRIMARY` | PRIMARY | `AssignmentID` |
| `uq_active_assignment` | UNIQUE | `ActiveBookingID` |
| `uq_driver_execution` | UNIQUE | `ActiveDriverID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_assignment_actor` | `AssignedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_assignment_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_assignment_driver` | `DriverID` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_assignment_station` | `StationID` | `stations.StationID` | RESTRICT / RESTRICT |

## `financial_entries`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `EntryID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `JournalID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `AccountKey` | `varchar(100)` | NO | `NULL` |  |
| `SignedMinor` | `bigint(20)` | NO | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_entry_account` | INDEX | `AccountKey`, `EntryID` |
| `idx_entry_journal` | INDEX | `JournalID` |
| `PRIMARY` | PRIMARY | `EntryID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_entry_journal` | `JournalID` | `financial_journals.JournalID` | RESTRICT / RESTRICT |

## `financial_journals`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `JournalID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `OperationKey` | `varchar(150)` | NO | `NULL` |  |
| `Kind` | `varchar(40)` | NO | `NULL` |  |
| `ContentHash` | `char(64)` | NO | `NULL` |  |
| `Book` | `enum('sandbox','cash','legacy_fixture')` | NO | `NULL` |  |
| `BookingID` | `int(11)` | YES | `NULL` |  |
| `OrderID` | `char(48)` | YES | `NULL` |  |
| `OwnerID` | `int(11)` | YES | `NULL` |  |
| `GrossMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `CommissionMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `OwnerMinor` | `bigint(20) unsigned` | YES | `NULL` |  |
| `DebitMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `CreditMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `Currency` | `char(3)` | NO | `'JOD'` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_journal_booking` | INDEX | `BookingID` |
| `fk_journal_order` | INDEX | `OrderID` |
| `idx_journal_owner` | INDEX | `OwnerID`, `Kind`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `JournalID` |
| `uq_journal_operation` | UNIQUE | `OperationKey` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_journal_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_journal_order` | `OrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |
| `fk_journal_owner` | `OwnerID` | `user.UserID` | RESTRICT / RESTRICT |

## `financial_receipts`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ReceiptID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `ReceiptNumber` | `varchar(40)` | NO | `NULL` |  |
| `JournalID` | `bigint(20) unsigned` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `BookingID` | `int(11)` | YES | `NULL` |  |
| `OrderID` | `char(48)` | YES | `NULL` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `EvidenceKind` | `varchar(40)` | NO | `NULL` |  |
| `IssuedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_fin_receipt_booking` | INDEX | `BookingID` |
| `fk_fin_receipt_order` | INDEX | `OrderID` |
| `idx_receipt_user` | INDEX | `UserID`, `ReceiptID` |
| `PRIMARY` | PRIMARY | `ReceiptID` |
| `uq_financial_receipt_journal` | UNIQUE | `JournalID` |
| `uq_financial_receipt_number` | UNIQUE | `ReceiptNumber` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_fin_receipt_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_fin_receipt_journal` | `JournalID` | `financial_journals.JournalID` | RESTRICT / RESTRICT |
| `fk_fin_receipt_order` | `OrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |
| `fk_fin_receipt_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `financial_settlements`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `SettlementID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `OwnerID` | `int(11)` | NO | `NULL` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `State` | `enum('blocked','pending','verified','cancelled')` | NO | `'blocked'` |  |
| `ProviderReference` | `varchar(150)` | YES | `NULL` |  |
| `EvidenceHash` | `char(64)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_settlement_owner` | INDEX | `OwnerID`, `SettlementID` |
| `PRIMARY` | PRIMARY | `SettlementID` |
| `uq_settlement_reference` | UNIQUE | `ProviderReference` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_settlement_owner` | `OwnerID` | `user.UserID` | RESTRICT / RESTRICT |

## `loyalty`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `LoyaltyID` | `int(11)` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `PointsBalance` | `decimal(10,2)` | YES | `0.00` |  |
| `LastUpdated` | `timestamp` | NO | `current_timestamp()` | on update current_timestamp() |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `LoyaltyID` |
| `UserID` | INDEX | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `loyalty_ibfk_1` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `mobile_devices`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `DeviceID` | `char(32)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `SessionID` | `char(64)` | NO | `NULL` |  |
| `TokenHash` | `char(64)` | NO | `NULL` |  |
| `TokenCipher` | `text` | YES | `[redacted]` |  |
| `Platform` | `enum('android','ios')` | NO | `NULL` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `RevokedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_device_user` | INDEX | `UserID`, `RevokedAt` |
| `PRIMARY` | PRIMARY | `DeviceID` |
| `uq_device_token` | UNIQUE | `TokenHash` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_device_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `notifications`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `user_id` | `int(11)` | YES | `NULL` |  |
| `target_role` | `varchar(50)` | YES | `NULL` |  |
| `title` | `varchar(255)` | NO | `NULL` |  |
| `title_en` | `varchar(255)` | YES | `NULL` |  |
| `body` | `text` | YES | `NULL` |  |
| `body_en` | `text` | YES | `NULL` |  |
| `target_url` | `varchar(255)` | YES | `NULL` |  |
| `type` | `enum('info','success','warning','danger')` | YES | `'info'` |  |
| `is_read` | `tinyint(1)` | YES | `0` |  |
| `created_at` | `datetime` | YES | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_notification_inbox` | INDEX | `user_id`, `created_at`, `id` |
| `idx_notification_role` | INDEX | `target_role`, `created_at`, `id` |
| `PRIMARY` | PRIMARY | `id` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `notifications_ibfk_1` | `user_id` | `user.UserID` | CASCADE / RESTRICT |

## `notification_deliveries`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `DeliveryID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `NotificationID` | `int(11)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `DeviceID` | `char(32)` | YES | `NULL` |  |
| `Channel` | `enum('push','email')` | NO | `NULL` |  |
| `DedupKey` | `char(64)` | NO | `NULL` |  |
| `State` | `enum('queued','sending','sent','skipped','dead')` | NO | `'queued'` |  |
| `Attempts` | `smallint(5) unsigned` | NO | `0` |  |
| `AvailableAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `LeaseUntil` | `datetime(6)` | YES | `NULL` |  |
| `Claim` | `char(32)` | YES | `NULL` |  |
| `LastError` | `varchar(40)` | YES | `NULL` |  |
| `SentAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_delivery_device` | INDEX | `DeviceID` |
| `fk_delivery_notification` | INDEX | `NotificationID` |
| `fk_delivery_user` | INDEX | `UserID` |
| `idx_delivery_due` | INDEX | `State`, `AvailableAt` |
| `PRIMARY` | PRIMARY | `DeliveryID` |
| `uq_delivery_intent` | UNIQUE | `DedupKey` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_delivery_device` | `DeviceID` | `mobile_devices.DeviceID` | RESTRICT / RESTRICT |
| `fk_delivery_notification` | `NotificationID` | `notifications.id` | RESTRICT / RESTRICT |
| `fk_delivery_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `notification_outbox`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `OutboxID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | YES | `NULL` |  |
| `TargetRole` | `varchar(50)` | YES | `NULL` |  |
| `Title` | `varchar(255)` | NO | `NULL` |  |
| `TitleEn` | `varchar(255)` | NO | `NULL` |  |
| `Body` | `text` | NO | `NULL` |  |
| `BodyEn` | `text` | NO | `NULL` |  |
| `Type` | `varchar(20)` | NO | `NULL` |  |
| `TargetUrl` | `varchar(255)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `DeliveredAt` | `datetime(6)` | YES | `NULL` |  |
| `NotificationID` | `int(11)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_outbox_notification` | INDEX | `NotificationID` |
| `fk_outbox_user` | INDEX | `UserID` |
| `idx_notification_delivery` | INDEX | `DeliveredAt`, `OutboxID` |
| `PRIMARY` | PRIMARY | `OutboxID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_outbox_notification` | `NotificationID` | `notifications.id` | RESTRICT / RESTRICT |
| `fk_outbox_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `notification_preferences`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `UserID` | `int(11)` | NO | `NULL` |  |
| `Language` | `enum('ar','en')` | NO | `'ar'` |  |
| `PushEnabled` | `tinyint(1)` | NO | `1` |  |
| `EmailEnabled` | `tinyint(1)` | NO | `1` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_preferences_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `notification_reads`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `NotificationID` | `int(11)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `ReadAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `notification_reads_user_fk` | INDEX | `UserID` |
| `PRIMARY` | PRIMARY | `NotificationID`, `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `notification_reads_notification_fk` | `NotificationID` | `notifications.id` | CASCADE / RESTRICT |
| `notification_reads_user_fk` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `owner_bank_accounts`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `owner_id` | `int(11)` | NO | `NULL` |  |
| `account_holder_name` | `varchar(150)` | NO | `NULL` |  |
| `bank_name` | `varchar(150)` | NO | `NULL` |  |
| `iban` | `varchar(34)` | NO | `NULL` |  |
| `account_number` | `varchar(50)` | YES | `NULL` |  |
| `swift_code` | `varchar(20)` | YES | `NULL` |  |
| `notes` | `varchar(255)` | YES | `NULL` |  |
| `updated_at` | `timestamp` | NO | `current_timestamp()` | on update current_timestamp() |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `owner_id` | UNIQUE | `owner_id` |
| `PRIMARY` | PRIMARY | `id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `owner_bank_accounts_owner_fk` | `owner_id` | `user.UserID` | CASCADE / RESTRICT |

## `owner_withdrawal_requests`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `owner_id` | `int(11)` | NO | `NULL` |  |
| `wallet_id` | `int(11)` | YES | `NULL` |  |
| `amount` | `decimal(10,2)` | NO | `NULL` |  |
| `status` | `enum('pending','paid','rejected')` | YES | `'pending'` |  |
| `bank_snapshot` | `text` | YES | `NULL` |  |
| `requested_at` | `timestamp` | NO | `current_timestamp()` |  |
| `processed_at` | `timestamp` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_withdrawal_owner` | INDEX | `owner_id`, `requested_at` |
| `idx_withdrawal_queue` | INDEX | `status`, `requested_at` |
| `owner_id` | INDEX | `owner_id` |
| `PRIMARY` | PRIMARY | `id` |
| `wallet_id` | INDEX | `wallet_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `owner_withdrawal_owner_fk` | `owner_id` | `user.UserID` | CASCADE / RESTRICT |
| `owner_withdrawal_wallet_fk` | `wallet_id` | `wallet.WalletID` | SET NULL / RESTRICT |

## `payments`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `PaymentID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `Method` | `varchar(20)` | NO | `NULL` |  |
| `Status` | `enum('pending','recorded','refunded')` | NO | `NULL` |  |
| `Amount` | `decimal(10,2)` | NO | `NULL` |  |
| `Currency` | `char(3)` | NO | `'JOD'` |  |
| `Provider` | `varchar(40)` | YES | `NULL` |  |
| `ProviderReference` | `varchar(150)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `RefundedAt` | `datetime(6)` | YES | `NULL` |  |
| `FundingOrderID` | `char(48)` | YES | `NULL` |  |
| `VerificationKind` | `varchar(40)` | YES | `NULL` |  |
| `VerifiedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_payment_user` | INDEX | `UserID`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `PaymentID` |
| `uq_payment_booking` | UNIQUE | `BookingID` |
| `uq_payment_provider` | UNIQUE | `Provider`, `ProviderReference` |
| `uq_receipt_funding` | UNIQUE | `FundingOrderID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_payment_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_payment_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_receipt_funding` | `FundingOrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |

## `payment_evidence`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `EvidenceID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `OrderID` | `char(48)` | NO | `NULL` |  |
| `EventHash` | `char(64)` | NO | `NULL` |  |
| `Source` | `enum('signed_webhook','authenticated_query')` | NO | `NULL` |  |
| `ProviderReference` | `varchar(150)` | NO | `NULL` |  |
| `TransactionType` | `varchar(20)` | NO | `NULL` |  |
| `ResultCode` | `varchar(8)` | NO | `NULL` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `ReceivedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_evidence_order` | INDEX | `OrderID`, `EvidenceID` |
| `PRIMARY` | PRIMARY | `EvidenceID` |
| `uq_evidence_event` | UNIQUE | `EventHash` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_evidence_order` | `OrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |

## `payment_orders`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `OrderID` | `char(48)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `BookingID` | `int(11)` | YES | `NULL` |  |
| `PlanID` | `int(11)` | YES | `NULL` |  |
| `Purpose` | `enum('booking','subscription')` | NO | `NULL` |  |
| `Provider` | `varchar(20)` | NO | `NULL` |  |
| `Environment` | `enum('sandbox')` | NO | `NULL` |  |
| `State` | `enum('prepared','creating','pending','succeeded','failed','cancelled','unknown','refund_due','refunded')` | NO | `'prepared'` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `Currency` | `char(3)` | NO | `'JOD'` |  |
| `ProviderReference` | `varchar(150)` | YES | `NULL` |  |
| `CheckoutCipher` | `text` | YES | `[redacted]` |  |
| `TermsSnapshot` | `longtext` | YES | `NULL` |  |
| `IntentKey` | `varchar(100)` | NO | `NULL` |  |
| `ActiveIntent` | `varchar(100)` | YES | `NULL` | STORED GENERATED |
| `CancelRequestedAt` | `datetime(6)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` | on update current_timestamp(6) |
| `ExpiresAt` | `datetime(6)` | NO | `NULL` |  |
| `VerifiedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_order_booking` | INDEX | `BookingID` |
| `fk_order_plan` | INDEX | `PlanID` |
| `idx_order_reconciliation` | INDEX | `State`, `UpdatedAt` |
| `idx_order_user` | INDEX | `UserID`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `OrderID` |
| `uq_order_intent` | UNIQUE | `ActiveIntent` |
| `uq_order_provider_reference` | UNIQUE | `Provider`, `Environment`, `ProviderReference` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_order_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_order_plan` | `PlanID` | `plans.id` | RESTRICT / RESTRICT |
| `fk_order_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `payment_sessions`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `SessionID` | `char(48)` | NO | `NULL` |  |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `Method` | `enum('wallet','cash','plan')` | NO | `NULL` |  |
| `UsePoints` | `tinyint(1)` | NO | `0` |  |
| `QuotedAmount` | `decimal(10,2)` | NO | `NULL` |  |
| `QuotedPoints` | `decimal(10,2)` | NO | `NULL` |  |
| `Status` | `enum('created','confirmed')` | NO | `'created'` |  |
| `PaymentID` | `bigint(20) unsigned` | YES | `NULL` |  |
| `ExpiresAt` | `datetime(6)` | NO | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_payment_session_booking` | INDEX | `BookingID` |
| `fk_payment_session_receipt` | INDEX | `PaymentID` |
| `idx_session_user` | INDEX | `UserID`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `SessionID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_payment_session_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_payment_session_receipt` | `PaymentID` | `payments.PaymentID` | RESTRICT / RESTRICT |
| `fk_payment_session_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `plans`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `name_ar` | `varchar(255)` | YES | `NULL` |  |
| `name_en` | `varchar(255)` | YES | `NULL` |  |
| `price` | `decimal(10,2)` | YES | `0.00` |  |
| `duration` | `int(11)` | YES | `1` |  |
| `duration_type` | `enum('day','month','year')` | YES | `'month'` |  |
| `wash_count` | `int(11)` | YES | `0` |  |
| `description_ar` | `text` | YES | `NULL` |  |
| `description_en` | `text` | YES | `NULL` |  |
| `included_services_ar` | `text` | YES | `NULL` |  |
| `included_services_en` | `text` | YES | `NULL` |  |
| `is_active` | `tinyint(1)` | YES | `1` |  |
| `created_at` | `datetime` | YES | `current_timestamp()` |  |
| `SandboxPolicy` | `longtext` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `id` |

No declared outbound foreign key.

## `provider_refunds`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `RefundID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `OrderID` | `char(48)` | NO | `NULL` |  |
| `RequestedBy` | `int(11)` | NO | `NULL` |  |
| `Origin` | `enum('customer_request','provider_notification')` | NO | `'customer_request'` |  |
| `Reason` | `varchar(500)` | NO | `NULL` |  |
| `State` | `enum('requested','submitting','pending','succeeded','failed','unknown')` | NO | `'requested'` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `ProviderReference` | `varchar(150)` | YES | `NULL` |  |
| `SubmittedBy` | `int(11)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` | on update current_timestamp(6) |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_provider_refund_actor` | INDEX | `RequestedBy` |
| `fk_provider_refund_admin` | INDEX | `SubmittedBy` |
| `PRIMARY` | PRIMARY | `RefundID` |
| `uq_provider_refund_order` | UNIQUE | `OrderID` |
| `uq_provider_refund_reference` | UNIQUE | `ProviderReference` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_provider_refund_actor` | `RequestedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_provider_refund_admin` | `SubmittedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_provider_refund_order` | `OrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |

## `refund_requests`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `RefundID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `Reason` | `varchar(2000)` | NO | `NULL` |  |
| `Status` | `enum('pending','approved','rejected')` | NO | `'pending'` |  |
| `DecisionNote` | `varchar(2000)` | YES | `NULL` |  |
| `ProcessedBy` | `int(11)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `ProcessedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_refund_processor` | INDEX | `ProcessedBy` |
| `idx_refund_queue` | INDEX | `Status`, `CreatedAt` |
| `idx_refund_user` | INDEX | `UserID`, `RefundID` |
| `PRIMARY` | PRIMARY | `RefundID` |
| `uq_refund_request_booking` | UNIQUE | `BookingID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_refund_processor` | `ProcessedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_refund_request_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_refund_request_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `request_idempotency`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `UserID` | `int(11)` | NO | `NULL` |  |
| `Scope` | `varchar(40)` | NO | `NULL` |  |
| `KeyHash` | `char(64)` | NO | `NULL` |  |
| `RequestHash` | `char(64)` | NO | `NULL` |  |
| `ResponseJson` | `text` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `RequestHashVersion` | `tinyint(3) unsigned` | NO | `1` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_request_hash_upgrade` | INDEX | `RequestHashVersion` |
| `PRIMARY` | PRIMARY | `UserID`, `Scope`, `KeyHash` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_idempotency_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `reviews`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ReviewID` | `int(11)` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | YES | `NULL` |  |
| `Rating` | `int(11)` | YES | `NULL` |  |
| `Comment` | `text` | YES | `NULL` |  |
| `Owner_Reply` | `text` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `BookingID` | INDEX | `BookingID` |
| `PRIMARY` | PRIMARY | `ReviewID` |
| `uq_reviews_booking` | UNIQUE | `BookingID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `reviews_ibfk_1` | `BookingID` | `booking.BookingID` | CASCADE / RESTRICT |

## `saved_addresses`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `AddressID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | NO | `NULL` |  |
| `Label` | `varchar(80)` | NO | `NULL` |  |
| `AddressText` | `varchar(2000)` | NO | `NULL` |  |
| `Latitude` | `decimal(10,8)` | NO | `NULL` |  |
| `Longitude` | `decimal(11,8)` | NO | `NULL` |  |
| `IsDefault` | `tinyint(1)` | NO | `0` |  |
| `ArchivedAt` | `datetime(6)` | YES | `NULL` |  |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` | on update current_timestamp(6) |
| `DefaultUserID` | `int(11)` | YES | `NULL` | STORED GENERATED |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_address_user` | INDEX | `UserID`, `ArchivedAt`, `AddressID` |
| `PRIMARY` | PRIMARY | `AddressID` |
| `uq_address_default` | UNIQUE | `DefaultUserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_address_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `security_mail_queue`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `PayloadCipher` | `mediumtext` | NO | `NULL` |  |
| `Attempts` | `smallint(5) unsigned` | NO | `0` |  |
| `AvailableAt` | `datetime` | NO | `current_timestamp()` |  |
| `SentAt` | `datetime` | YES | `NULL` |  |
| `LeaseUntil` | `datetime(6)` | YES | `NULL` |  |
| `Claim` | `char(32)` | YES | `NULL` |  |
| `LastError` | `varchar(40)` | YES | `NULL` |  |
| `UserID` | `int(11)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_mail_ready` | INDEX | `SentAt`, `AvailableAt` |
| `idx_security_mail_user` | INDEX | `UserID`, `SentAt` |
| `PRIMARY` | PRIMARY | `ID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_security_mail_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

## `security_migration_steps`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `Version` | `varchar(100)` | NO | `NULL` |  |
| `StepNumber` | `int(11)` | NO | `NULL` |  |
| `Checksum` | `char(64)` | NO | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `Version`, `StepNumber` |

No declared outbound foreign key.

## `security_rate_limits`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `BucketKey` | `char(64)` | NO | `NULL` |  |
| `WindowStart` | `bigint(20)` | NO | `NULL` |  |
| `Hits` | `int(10) unsigned` | NO | `NULL` |  |
| `ExpiresAt` | `bigint(20)` | NO | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_rate_expiry` | INDEX | `ExpiresAt` |
| `PRIMARY` | PRIMARY | `BucketKey`, `WindowStart` |

No declared outbound foreign key.

## `security_schema_migrations`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `Version` | `varchar(100)` | NO | `NULL` |  |
| `Checksum` | `char(64)` | NO | `NULL` |  |
| `AppliedAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `Version` |

No declared outbound foreign key.

## `services`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ServiceID` | `int(11)` | NO | `NULL` | auto_increment |
| `StationID` | `int(11)` | YES | `NULL` |  |
| `Name` | `varchar(100)` | YES | `NULL` |  |
| `Name_ar` | `varchar(100)` | YES | `NULL` |  |
| `Name_en` | `varchar(100)` | YES | `NULL` |  |
| `Description` | `text` | YES | `NULL` |  |
| `Description_ar` | `text` | YES | `NULL` |  |
| `Description_en` | `text` | YES | `NULL` |  |
| `Price` | `decimal(10,2)` | YES | `NULL` |  |
| `Duration` | `int(11)` | YES | `NULL` |  |
| `is_station` | `tinyint(1)` | YES | `1` |  |
| `is_home` | `tinyint(1)` | YES | `0` |  |
| `is_pickup` | `tinyint(1)` | YES | `0` |  |
| `ArchivedAt` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_service_catalog` | INDEX | `StationID`, `ArchivedAt` |
| `PRIMARY` | PRIMARY | `ServiceID` |
| `StationID` | INDEX | `StationID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `services_ibfk_1` | `StationID` | `stations.StationID` | CASCADE / RESTRICT |

## `stations`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `StationID` | `int(11)` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | YES | `NULL` |  |
| `Name` | `varchar(100)` | YES | `NULL` |  |
| `Location` | `varchar(255)` | YES | `NULL` |  |
| `Location_URL` | `varchar(255)` | YES | `NULL` |  |
| `Lat` | `decimal(10,8)` | YES | `NULL` |  |
| `Lon` | `decimal(11,8)` | YES | `NULL` |  |
| `PhoneNumber` | `varchar(20)` | YES | `NULL` |  |
| `Rating` | `decimal(3,2)` | YES | `4.80` |  |
| `Status` | `enum('Active','Busy','Closed')` | YES | `'Active'` |  |
| `admin_status` | `varchar(20)` | YES | `'pending'` |  |
| `CommercialRecordPath` | `varchar(255)` | YES | `NULL` |  |
| `Image` | `varchar(255)` | YES | `'default_station.png'` |  |
| `Has_At_Station` | `tinyint(1)` | YES | `1` |  |
| `Has_Home_Service` | `tinyint(1)` | YES | `0` |  |
| `Has_Delivery` | `tinyint(1)` | YES | `0` |  |
| `OpeningTime` | `time` | YES | `'08:00:00'` |  |
| `ClosingTime` | `time` | YES | `'22:00:00'` |  |
| `ArchivedAt` | `datetime(6)` | YES | `NULL` |  |
| `BookingCapacity` | `smallint(5) unsigned` | YES | `NULL` |  |
| `HomeCapacity` | `smallint(5) unsigned` | YES | `NULL` |  |
| `DefaultServiceMinutes` | `smallint(5) unsigned` | NO | `30` |  |
| `DeliveryBufferMinutes` | `smallint(5) unsigned` | NO | `0` |  |
| `StationFee` | `decimal(10,2)` | NO | `0.00` |  |
| `HomeFee` | `decimal(10,2)` | NO | `0.00` |  |
| `DeliveryFee` | `decimal(10,2)` | NO | `0.00` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_station_catalog` | INDEX | `admin_status`, `Status`, `ArchivedAt` |
| `PRIMARY` | PRIMARY | `StationID` |
| `UserID` | INDEX | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `stations_ibfk_1` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `subscription_redemptions`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `RedemptionID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `UserPlanID` | `int(11)` | NO | `NULL` |  |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `SlotNumber` | `int(11)` | NO | `NULL` |  |
| `AmountMinor` | `bigint(20) unsigned` | NO | `NULL` |  |
| `State` | `enum('reserved','completed','released')` | NO | `'reserved'` |  |
| `ActiveSlot` | `int(11)` | YES | `NULL` | STORED GENERATED |
| `CreatedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `UpdatedAt` | `datetime(6)` | NO | `current_timestamp(6)` | on update current_timestamp(6) |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `RedemptionID` |
| `uq_redemption_booking` | UNIQUE | `BookingID` |
| `uq_redemption_slot` | UNIQUE | `UserPlanID`, `ActiveSlot` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_redemption_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_redemption_plan` | `UserPlanID` | `user_plans.id` | RESTRICT / RESTRICT |

## `support_messages`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `MessageID` | `int(11)` | NO | `NULL` | auto_increment |
| `TicketID` | `int(11)` | NO | `NULL` |  |
| `SenderRole` | `enum('user','admin')` | NO | `NULL` |  |
| `SenderID` | `int(11)` | YES | `NULL` |  |
| `Message` | `text` | NO | `NULL` |  |
| `CreatedAt` | `datetime` | NO | `current_timestamp()` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_support_sender` | INDEX | `SenderID` |
| `idx_support_thread` | INDEX | `TicketID`, `CreatedAt`, `MessageID` |
| `PRIMARY` | PRIMARY | `MessageID` |
| `SenderRole` | INDEX | `SenderRole` |
| `TicketID` | INDEX | `TicketID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_support_sender` | `SenderID` | `user.UserID` | RESTRICT / RESTRICT |
| `support_messages_ticket_fk` | `TicketID` | `support_tickets.id` | CASCADE / RESTRICT |

## `support_tickets`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `user_id` | `int(11)` | YES | `NULL` |  |
| `user_name` | `varchar(255)` | YES | `NULL` |  |
| `user_type` | `enum('user','owner','driver')` | YES | `'user'` |  |
| `guest_email` | `varchar(100)` | YES | `NULL` |  |
| `subject` | `varchar(255)` | YES | `NULL` |  |
| `message` | `text` | YES | `NULL` |  |
| `status` | `enum('New','Replied','Closed')` | YES | `'New'` |  |
| `is_guest` | `tinyint(1)` | YES | `0` |  |
| `created_at` | `datetime` | YES | `current_timestamp()` |  |
| `Category` | `enum('support','dispute')` | NO | `'support'` |  |
| `BookingID` | `int(11)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_ticket_booking` | INDEX | `BookingID` |
| `idx_ticket_admin` | INDEX | `Category`, `status`, `id` |
| `idx_ticket_inbox` | INDEX | `user_id`, `status`, `id` |
| `PRIMARY` | PRIMARY | `id` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_ticket_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `support_tickets_ibfk_1` | `user_id` | `user.UserID` | SET NULL / RESTRICT |

## `tracking_records`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `TrackingID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `BookingID` | `int(11)` | NO | `NULL` |  |
| `DriverID` | `int(11)` | NO | `NULL` |  |
| `Latitude` | `decimal(10,8)` | NO | `NULL` |  |
| `Longitude` | `decimal(11,8)` | NO | `NULL` |  |
| `RecordedAt` | `datetime(6)` | NO | `current_timestamp(6)` |  |
| `AssignmentID` | `bigint(20) unsigned` | YES | `NULL` |  |
| `SequenceNumber` | `bigint(20) unsigned` | YES | `NULL` |  |
| `CapturedAt` | `datetime(6)` | YES | `NULL` |  |
| `AccuracyMeters` | `decimal(8,2)` | YES | `NULL` |  |
| `CaptureMode` | `enum('foreground','background')` | YES | `NULL` |  |
| `SampleHash` | `char(64)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_tracking_driver` | INDEX | `DriverID` |
| `idx_tracking_booking` | INDEX | `BookingID`, `RecordedAt`, `TrackingID` |
| `idx_tracking_retention` | INDEX | `RecordedAt`, `TrackingID` |
| `PRIMARY` | PRIMARY | `TrackingID` |
| `uq_tracking_sequence` | UNIQUE | `AssignmentID`, `SequenceNumber` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_tracking_assignment` | `AssignmentID` | `driver_assignments.AssignmentID` | RESTRICT / RESTRICT |
| `fk_tracking_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_tracking_driver` | `DriverID` | `user.UserID` | RESTRICT / RESTRICT |

## `user`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `UserID` | `int(11)` | NO | `NULL` | auto_increment |
| `UserName` | `varchar(100)` | NO | `NULL` |  |
| `ProfilePic` | `varchar(255)` | YES | `NULL` |  |
| `Email` | `varchar(100)` | YES | `NULL` |  |
| `Password` | `varchar(255)` | NO | `NULL` |  |
| `PhoneNumber` | `varchar(20)` | NO | `NULL` |  |
| `CarName` | `varchar(100)` | YES | `NULL` |  |
| `Role` | `varchar(50)` | YES | `'User'` |  |
| `AccountStatus` | `enum('active','pending','rejected')` | YES | `'pending'` |  |
| `VerificationFile` | `varchar(255)` | YES | `NULL` |  |
| `VerificationToken` | `varchar(255)` | YES | `[redacted]` |  |
| `DeviceID` | `varchar(100)` | YES | `NULL` |  |
| `CurrentAccessCode` | `varchar(10)` | YES | `[redacted]` |  |
| `reset_otp` | `varchar(10)` | YES | `[redacted]` |  |
| `reset_otp_expiry` | `datetime` | YES | `[redacted]` |  |
| `StationID` | `int(11)` | YES | `NULL` |  |
| `LastLatitude` | `decimal(10,8)` | YES | `NULL` |  |
| `LastLongitude` | `decimal(11,8)` | YES | `NULL` |  |
| `LastAddress` | `text` | YES | `NULL` |  |
| `LastLocationUrl` | `varchar(255)` | YES | `NULL` |  |
| `LastLocationUpdatedAt` | `timestamp` | YES | `NULL` |  |
| `Last_Login` | `timestamp` | YES | `NULL` |  |
| `AuthVersion` | `int(10) unsigned` | NO | `1` |  |
| `EmailVerifiedAt` | `datetime` | YES | `NULL` |  |
| `MustResetPassword` | `tinyint(1)` | NO | `[redacted]` |  |
| `PendingEmail` | `varchar(100)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `Email` | UNIQUE | `Email` |
| `idx_user_role_station` | INDEX | `Role`, `StationID` |
| `idx_user_station` | INDEX | `StationID` |
| `PhoneNumber` | UNIQUE | `PhoneNumber` |
| `PRIMARY` | PRIMARY | `UserID` |
| `VerificationToken` | UNIQUE | `VerificationToken` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `db003_user_station` | `StationID` | `stations.StationID` | RESTRICT / RESTRICT |

## `user_plans`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `id` | `int(11)` | NO | `NULL` | auto_increment |
| `user_id` | `int(11)` | YES | `NULL` |  |
| `plan_id` | `int(11)` | YES | `NULL` |  |
| `start_date` | `date` | YES | `NULL` |  |
| `end_date` | `date` | YES | `NULL` |  |
| `status` | `enum('active','expired','cancelled')` | YES | `'active'` |  |
| `remaining_washes` | `int(11)` | YES | `0` |  |
| `created_at` | `datetime` | YES | `current_timestamp()` |  |
| `PurchasePrice` | `decimal(10,2)` | YES | `NULL` |  |
| `PurchasedWashes` | `int(11)` | YES | `NULL` |  |
| `PlanNameSnapshot` | `varchar(255)` | YES | `NULL` |  |
| `FundingOrderID` | `char(48)` | YES | `NULL` |  |
| `TermsSnapshot` | `longtext` | YES | `NULL` |  |
| `PaymentHold` | `tinyint(1)` | NO | `0` |  |
| `StartsAtUtc` | `datetime(6)` | YES | `NULL` |  |
| `ExpiresAtUtc` | `datetime(6)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_user_plan_active` | INDEX | `user_id`, `status`, `end_date`, `created_at` |
| `plan_id` | INDEX | `plan_id` |
| `PRIMARY` | PRIMARY | `id` |
| `uq_subscription_funding` | UNIQUE | `FundingOrderID` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_subscription_funding` | `FundingOrderID` | `payment_orders.OrderID` | RESTRICT / RESTRICT |
| `user_plans_ibfk_1` | `user_id` | `user.UserID` | CASCADE / RESTRICT |
| `user_plans_ibfk_2` | `plan_id` | `plans.id` | CASCADE / RESTRICT |

## `wallet`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `WalletID` | `int(11)` | NO | `NULL` | auto_increment |
| `UserID` | `int(11)` | YES | `NULL` |  |
| `Balance` | `decimal(10,2)` | YES | `0.00` |  |
| `Last_Updated` | `timestamp` | NO | `current_timestamp()` | on update current_timestamp() |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `WalletID` |
| `uq_wallet_user` | UNIQUE | `UserID` |
| `UserID` | INDEX | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `wallet_ibfk_1` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

## `wallet_transactions`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `TransactionID` | `int(11)` | NO | `NULL` | auto_increment |
| `WalletID` | `int(11)` | YES | `NULL` |  |
| `Amount` | `decimal(10,2)` | YES | `NULL` |  |
| `TransactionType` | `enum('credit','debit')` | YES | `NULL` |  |
| `Description_ar` | `varchar(255)` | YES | `NULL` |  |
| `Description_en` | `varchar(255)` | YES | `NULL` |  |
| `CreatedAt` | `timestamp` | NO | `current_timestamp()` |  |
| `OperationKey` | `varchar(100)` | YES | `NULL` |  |
| `BookingID` | `int(11)` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_wallet_transaction_booking` | INDEX | `BookingID` |
| `idx_wallet_history` | INDEX | `WalletID`, `CreatedAt`, `TransactionID` |
| `PRIMARY` | PRIMARY | `TransactionID` |
| `uq_wallet_operation` | UNIQUE | `OperationKey` |
| `WalletID` | INDEX | `WalletID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_wallet_transaction_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `wallet_transactions_ibfk_1` | `WalletID` | `wallet.WalletID` | CASCADE / RESTRICT |
