-- Reviewed legacy schema only. Initialize EMPTY databases with scripts/initialize-database.php.
CREATE TABLE IF NOT EXISTS `user` (
  `UserID` int(11) NOT NULL AUTO_INCREMENT,
  `UserName` varchar(100) NOT NULL,
  `ProfilePic` varchar(255) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Password` varchar(255) NOT NULL,
  `PhoneNumber` varchar(20) NOT NULL,
  `CarName` varchar(100) DEFAULT NULL,
  `Role` varchar(50) DEFAULT 'User',
  `AccountStatus` enum('active','pending','rejected') DEFAULT 'pending',
  `VerificationFile` varchar(255) DEFAULT NULL,
  `VerificationToken` varchar(255) DEFAULT NULL,
  `DeviceID` varchar(100) DEFAULT NULL,
  `CurrentAccessCode` varchar(10) DEFAULT NULL,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_otp_expiry` datetime DEFAULT NULL,
  `StationID` int(11) DEFAULT NULL,
  `LastLatitude` decimal(10,8) DEFAULT NULL,
  `LastLongitude` decimal(11,8) DEFAULT NULL,
  `LastAddress` text DEFAULT NULL,
  `LastLocationUrl` varchar(255) DEFAULT NULL,
  `LastLocationUpdatedAt` timestamp NULL DEFAULT NULL,
  `Last_Login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `PhoneNumber` (`PhoneNumber`),
  UNIQUE KEY `Email` (`Email`),
  UNIQUE KEY `VerificationToken` (`VerificationToken`),
  KEY `idx_user_role_station` (`Role`, `StationID`),
  KEY `idx_user_station` (`StationID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=3;

CREATE TABLE IF NOT EXISTS `blacklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `DeviceID` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `blacklist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stations` (
  `StationID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) DEFAULT NULL,
  `Name` varchar(100) DEFAULT NULL,
  `Location` varchar(255) DEFAULT NULL,
  `Location_URL` varchar(255) DEFAULT NULL,
  `Lat` decimal(10,8) DEFAULT NULL,
  `Lon` decimal(11,8) DEFAULT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `Rating` decimal(3,2) DEFAULT 4.80,
  `Status` enum('Active','Busy','Closed') DEFAULT 'Active',
  `admin_status` varchar(20) DEFAULT 'pending',
  `CommercialRecordPath` varchar(255) DEFAULT NULL,
  `Image` varchar(255) DEFAULT 'default_station.png',
  `Has_At_Station` tinyint(1) DEFAULT 1,
  `Has_Home_Service` tinyint(1) DEFAULT 0,
  `Has_Delivery` tinyint(1) DEFAULT 0,
  `OpeningTime` time DEFAULT '08:00:00',
  `ClosingTime` time DEFAULT '22:00:00',
  PRIMARY KEY (`StationID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `stations_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `services` (
  `ServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `StationID` int(11) DEFAULT NULL,
  `Name` varchar(100) DEFAULT NULL,
  `Name_ar` varchar(100) DEFAULT NULL,
  `Name_en` varchar(100) DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Description_ar` text DEFAULT NULL,
  `Description_en` text DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT NULL,
  `Duration` int(11) DEFAULT NULL,
  `is_station` tinyint(1) DEFAULT 1,
  `is_home` tinyint(1) DEFAULT 0,
  `is_pickup` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`ServiceID`),
  KEY `StationID` (`StationID`),
  CONSTRAINT `services_ibfk_1` FOREIGN KEY (`StationID`) REFERENCES `stations` (`StationID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(255) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `duration` int(11) DEFAULT 1,
  `duration_type` enum('day','month','year') DEFAULT 'month',
  `wash_count` int(11) DEFAULT 0,
  `description_ar` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `included_services_ar` text DEFAULT NULL,
  `included_services_en` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','expired','cancelled') DEFAULT 'active',
  `remaining_washes` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `user_plans_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `user_plans_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking` (
  `BookingID` int(11) NOT NULL AUTO_INCREMENT,
  `Order_Number` varchar(20) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `StationID` int(11) DEFAULT NULL,
  `ServiceID` int(11) DEFAULT NULL,
  `DriverID` int(11) DEFAULT NULL,
  `DriverAccessCode` varchar(10) DEFAULT NULL,
  `Service_Location` enum('station','home','delivery') DEFAULT 'station',
  `Latitude` decimal(10,8) DEFAULT NULL,
  `Longitude` decimal(11,8) DEFAULT NULL,
  `Driver_Lat` decimal(10,8) DEFAULT NULL,
  `Driver_Lon` decimal(11,8) DEFAULT NULL,
  `Address_Details` text DEFAULT NULL,
  `Booking_Date` date DEFAULT NULL,
  `Booking_Time` time DEFAULT NULL,
  `Status` enum('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed') DEFAULT 'Pending',
  `Total_Price` decimal(10,2) DEFAULT NULL,
  `Admin_Commission` decimal(10,2) DEFAULT NULL,
  `Payment_Method` varchar(50) DEFAULT NULL,
  `Points_Used` decimal(10,2) DEFAULT 0.00,
  `Points_Discount` decimal(10,2) DEFAULT 0.00,
  `Payable_Amount` decimal(10,2) DEFAULT NULL,
  `UserPlanID` int(11) DEFAULT NULL,
  `Plan_Washes_Used` tinyint(1) DEFAULT 0,
  `Accepted_At` datetime DEFAULT NULL,
  `notification_sent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BookingID`),
  KEY `UserID` (`UserID`),
  KEY `StationID` (`StationID`),
  KEY `ServiceID` (`ServiceID`),
  KEY `DriverID` (`DriverID`),
  UNIQUE KEY `uq_booking_driver_access_code` (`DriverAccessCode`),
  KEY `UserPlanID` (`UserPlanID`),
  CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`StationID`) REFERENCES `stations` (`StationID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_3` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_4` FOREIGN KEY (`DriverID`) REFERENCES `user` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `booking_ibfk_5` FOREIGN KEY (`UserPlanID`) REFERENCES `user_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_services` (
  `BookingServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `BookingID` int(11) NOT NULL,
  `ServiceID` int(11) DEFAULT NULL,
  `ServiceName` varchar(255) DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`BookingServiceID`),
  KEY `BookingID` (`BookingID`),
  KEY `ServiceID` (`ServiceID`),
  CONSTRAINT `booking_services_booking_fk` FOREIGN KEY (`BookingID`) REFERENCES `booking` (`BookingID`) ON DELETE CASCADE,
  CONSTRAINT `booking_services_service_fk` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reviews` (
  `ReviewID` int(11) NOT NULL AUTO_INCREMENT,
  `BookingID` int(11) DEFAULT NULL,
  `Rating` int(11) DEFAULT NULL,
  `Comment` text DEFAULT NULL,
  `Owner_Reply` text DEFAULT NULL,
  PRIMARY KEY (`ReviewID`),
  KEY `BookingID` (`BookingID`),
  UNIQUE KEY `uq_reviews_booking` (`BookingID`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`BookingID`) REFERENCES `booking` (`BookingID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `loyalty` (
  `LoyaltyID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `PointsBalance` decimal(10,2) DEFAULT 0.00,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`LoyaltyID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `loyalty_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL, -- الحقل المضاف لربط برمجة الإشعارات والتحقق من صلاحيات المشاهدة
  `title` varchar(255) NOT NULL,
  `title_en` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `body_en` text DEFAULT NULL,
  `target_url` varchar(255) DEFAULT NULL,
  `type` enum('info','success','warning','danger') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `user_type` enum('user','owner','driver') DEFAULT 'user',
  `guest_email` varchar(100) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('New','Replied','Closed') DEFAULT 'New',
  `is_guest` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `support_messages` (
  `MessageID` int(11) NOT NULL AUTO_INCREMENT,
  `TicketID` int(11) NOT NULL,
  `SenderRole` enum('user','admin') NOT NULL,
  `SenderID` int(11) DEFAULT NULL,
  `Message` text NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`MessageID`),
  KEY `TicketID` (`TicketID`),
  KEY `SenderRole` (`SenderRole`),
  CONSTRAINT `support_messages_ticket_fk` FOREIGN KEY (`TicketID`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wallet` (
  `WalletID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) DEFAULT NULL,
  `Balance` decimal(10,2) DEFAULT 0.00,
  `Last_Updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`WalletID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `wallet_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wallet_transactions` (
  `TransactionID` int(11) NOT NULL AUTO_INCREMENT,
  `WalletID` int(11) DEFAULT NULL,
  `Amount` decimal(10,2) DEFAULT NULL,
  `TransactionType` enum('credit','debit') DEFAULT NULL,
  `Description_ar` varchar(255) DEFAULT NULL,
  `Description_en` varchar(255) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`TransactionID`),
  KEY `WalletID` (`WalletID`),
  CONSTRAINT `wallet_transactions_ibfk_1` FOREIGN KEY (`WalletID`) REFERENCES `wallet` (`WalletID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `owner_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `account_holder_name` varchar(150) NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `iban` varchar(34) NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `swift_code` varchar(20) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_id` (`owner_id`),
  CONSTRAINT `owner_bank_accounts_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `owner_withdrawal_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `wallet_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','rejected') DEFAULT 'pending',
  `bank_snapshot` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `wallet_id` (`wallet_id`),
  CONSTRAINT `owner_withdrawal_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `owner_withdrawal_wallet_fk` FOREIGN KEY (`wallet_id`) REFERENCES `wallet` (`WalletID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `user`
  ADD CONSTRAINT `user_station_fk` FOREIGN KEY (`StationID`) REFERENCES `stations` (`StationID`) ON DELETE SET NULL;
