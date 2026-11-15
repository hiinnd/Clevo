# CLEVO schema inventory

Generated from actual migrated disposable metadata on 2026-11-15. Engine: `10.4.32-MariaDB`; session timezone: `+00:00`. Tables: 32; columns: 277; index columns: 142; foreign-key columns: 43. This is not a live production inventory.

Source: `scripts/database-inventory.php` output after 001/002/003. Reproduce with `php scripts/render-schema-docs.php <protected-inventory.json>`. No customer data is included; sensitive defaults are redacted. Existing FK delete rules are preserved when present, so a deployed variant may differ from this copy. Read [DATABASE_CHANGES.md](DATABASE_CHANGES.md) for semantics, anomalies, compatibility and release gates; [DATABASE_ERD.md](DATABASE_ERD.md) for the graph.

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `DriverID` | INDEX | `DriverID` |
| `fk_booking_owner_snapshot` | INDEX | `OwnerIDSnapshot` |
| `idx_booking_driver_status` | INDEX | `DriverID`, `Status` |
| `idx_booking_expiry` | INDEX | `Status`, `notification_sent`, `created_at` |
| `idx_booking_station_status_date` | INDEX | `StationID`, `Status`, `Booking_Date` |
| `idx_booking_user_status_created` | INDEX | `UserID`, `Status`, `created_at` |
| `PRIMARY` | PRIMARY | `BookingID` |
| `ServiceID` | INDEX | `ServiceID` |
| `StationID` | INDEX | `StationID` |
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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `BookingID` | INDEX | `BookingID` |
| `PRIMARY` | PRIMARY | `BookingServiceID` |
| `ServiceID` | INDEX | `ServiceID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `booking_services_booking_fk` | `BookingID` | `booking.BookingID` | CASCADE / RESTRICT |
| `booking_services_service_fk` | `ServiceID` | `services.ServiceID` | SET NULL / RESTRICT |

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_assignment_actor` | INDEX | `AssignedBy` |
| `fk_assignment_station` | INDEX | `StationID` |
| `idx_assignment_booking` | INDEX | `BookingID`, `AssignedAt` |
| `idx_assignment_driver` | INDEX | `DriverID`, `EndedAt` |
| `PRIMARY` | PRIMARY | `AssignmentID` |
| `uq_active_assignment` | UNIQUE | `ActiveBookingID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_assignment_actor` | `AssignedBy` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_assignment_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_assignment_driver` | `DriverID` | `user.UserID` | RESTRICT / RESTRICT |
| `fk_assignment_station` | `StationID` | `stations.StationID` | RESTRICT / RESTRICT |

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_payment_user` | INDEX | `UserID`, `CreatedAt` |
| `PRIMARY` | PRIMARY | `PaymentID` |
| `uq_payment_booking` | UNIQUE | `BookingID` |
| `uq_payment_provider` | UNIQUE | `Provider`, `ProviderReference` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `fk_payment_booking` | `BookingID` | `booking.BookingID` | RESTRICT / RESTRICT |
| `fk_payment_user` | `UserID` | `user.UserID` | RESTRICT / RESTRICT |

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `id` |

No declared outbound foreign key.

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
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

## `security_mail_queue`

Engine: `InnoDB`; collation: `utf8mb4_general_ci`.

| Column | SQL type | Null | Default | Extra |
|---|---|---|---|---|
| `ID` | `bigint(20) unsigned` | NO | `NULL` | auto_increment |
| `PayloadCipher` | `mediumtext` | NO | `NULL` |  |
| `Attempts` | `smallint(5) unsigned` | NO | `0` |  |
| `AvailableAt` | `datetime` | NO | `current_timestamp()` |  |
| `SentAt` | `datetime` | YES | `NULL` |  |

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_mail_ready` | INDEX | `SentAt`, `AvailableAt` |
| `PRIMARY` | PRIMARY | `ID` |

No declared outbound foreign key.

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_station_catalog` | INDEX | `admin_status`, `Status`, `ArchivedAt` |
| `PRIMARY` | PRIMARY | `StationID` |
| `UserID` | INDEX | `UserID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
| `stations_ibfk_1` | `UserID` | `user.UserID` | CASCADE / RESTRICT |

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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `PRIMARY` | PRIMARY | `id` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `fk_tracking_driver` | INDEX | `DriverID` |
| `idx_tracking_booking` | INDEX | `BookingID`, `RecordedAt`, `TrackingID` |
| `PRIMARY` | PRIMARY | `TrackingID` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
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

| Index | Kind | Ordered columns / prefix |
|---|---|---|
| `idx_user_plan_active` | INDEX | `user_id`, `status`, `end_date`, `created_at` |
| `plan_id` | INDEX | `plan_id` |
| `PRIMARY` | PRIMARY | `id` |
| `user_id` | INDEX | `user_id` |

| FK | Child column | Parent | Delete / update |
|---|---|---|---|
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
