# CLEVO entity relationship diagram

Generated from the same actual metadata as [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md). Every declared FK is represented. Parent optionality follows child nullability; child maximum-one is shown for single-column unique FKs. Composite unique constraints may impose additional limits; see the full index inventory. Role/station equality, payment state, booking lifecycle and immutable history are application policies, not implied by the graph.

```mermaid
erDiagram
    audit_logs {
        bigint AuditID PK
    }
    auth_challenges {
        char ChallengeID PK
    }
    auth_refresh_tokens {
        char TokenHash PK
    }
    auth_sessions {
        char SessionID PK
    }
    blacklist {
        int id PK
    }
    booking {
        int BookingID PK
    }
    booking_effects {
        int BookingID PK
        varchar Effect PK
    }
    booking_services {
        int BookingServiceID PK
    }
    database_migration_steps {
        varchar Version PK
        int StepNumber PK
    }
    driver_assignments {
        bigint AssignmentID PK
    }
    loyalty {
        int LoyaltyID PK
    }
    notifications {
        int id PK
    }
    notification_reads {
        int NotificationID PK
        int UserID PK
    }
    owner_bank_accounts {
        int id PK
    }
    owner_withdrawal_requests {
        int id PK
    }
    payments {
        bigint PaymentID PK
    }
    plans {
        int id PK
    }
    request_idempotency {
        int UserID PK
        varchar Scope PK
        char KeyHash PK
    }
    reviews {
        int ReviewID PK
    }
    security_mail_queue {
        bigint ID PK
    }
    security_migration_steps {
        varchar Version PK
        int StepNumber PK
    }
    security_rate_limits {
        char BucketKey PK
        bigint WindowStart PK
    }
    security_schema_migrations {
        varchar Version PK
    }
    services {
        int ServiceID PK
    }
    stations {
        int StationID PK
    }
    support_messages {
        int MessageID PK
    }
    support_tickets {
        int id PK
    }
    tracking_records {
        bigint TrackingID PK
    }
    user {
        int UserID PK
    }
    user_plans {
        int id PK
    }
    wallet {
        int WalletID PK
    }
    wallet_transactions {
        int TransactionID PK
    }
    user |o--o{ audit_logs : "ActorID"
    user ||--o{ auth_challenges : "UserID"
    auth_sessions ||--o{ auth_refresh_tokens : "SessionID"
    booking |o--o{ auth_sessions : "DriverBookingID"
    user ||--o{ auth_sessions : "UserID"
    user |o--o{ blacklist : "user_id"
    user |o--o{ booking : "UserID"
    stations |o--o{ booking : "StationID"
    services |o--o{ booking : "ServiceID"
    user |o--o{ booking : "DriverID"
    user_plans |o--o{ booking : "UserPlanID"
    user |o--o{ booking : "OwnerIDSnapshot"
    booking ||--o{ booking_effects : "BookingID"
    booking ||--o{ booking_services : "BookingID"
    services |o--o{ booking_services : "ServiceID"
    user ||--o{ driver_assignments : "AssignedBy"
    booking ||--o{ driver_assignments : "BookingID"
    user ||--o{ driver_assignments : "DriverID"
    stations ||--o{ driver_assignments : "StationID"
    user ||--o{ loyalty : "UserID"
    user |o--o{ notifications : "user_id"
    notifications ||--o{ notification_reads : "NotificationID"
    user ||--o{ notification_reads : "UserID"
    user ||--o| owner_bank_accounts : "owner_id"
    user ||--o{ owner_withdrawal_requests : "owner_id"
    wallet |o--o{ owner_withdrawal_requests : "wallet_id"
    booking ||--o| payments : "BookingID"
    user ||--o{ payments : "UserID"
    user ||--o{ request_idempotency : "UserID"
    booking |o--o| reviews : "BookingID"
    stations |o--o{ services : "StationID"
    user |o--o{ stations : "UserID"
    user |o--o{ support_messages : "SenderID"
    support_tickets ||--o{ support_messages : "TicketID"
    user |o--o{ support_tickets : "user_id"
    booking ||--o{ tracking_records : "BookingID"
    user ||--o{ tracking_records : "DriverID"
    stations |o--o{ user : "StationID"
    user |o--o{ user_plans : "user_id"
    plans |o--o{ user_plans : "plan_id"
    user |o--o| wallet : "UserID"
    booking |o--o{ wallet_transactions : "BookingID"
    wallet |o--o{ wallet_transactions : "WalletID"
```

`booking.DriverID`, `user.StationID` and booking GPS columns remain current-state caches; assignment/tracking tables retain new history. Original cascade rules shown in the schema are not permission to delete historical records. See [DATABASE_BACKUP_RESTORE.md](DATABASE_BACKUP_RESTORE.md) for non-destructive deployment/recovery.
