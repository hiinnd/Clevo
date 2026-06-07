SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- Make import idempotent: drop tables in safe order, then recreate
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `wallet_transactions`;
DROP TABLE IF EXISTS `owner_withdrawal_requests`;
DROP TABLE IF EXISTS `owner_bank_accounts`;
DROP TABLE IF EXISTS `wallet`;
DROP TABLE IF EXISTS `support_messages`;
DROP TABLE IF EXISTS `support_tickets`;
DROP TABLE IF EXISTS `user_plans`;
DROP TABLE IF EXISTS `plans`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `loyalty`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `booking_services`;
DROP TABLE IF EXISTS `booking`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `stations`;
DROP TABLE IF EXISTS `blacklist`;
DROP TABLE IF EXISTS `user`;
SET FOREIGN_KEY_CHECKS=1;

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
  UNIQUE KEY `VerificationToken` (`VerificationToken`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=3;

-- الحسابات الافتراضية للوحة التحكم (لم يتم حذفها أو تعديلها)
INSERT INTO `user`
(`UserID`, `UserName`, `ProfilePic`, `Email`, `Password`, `PhoneNumber`, `CarName`, `Role`, `AccountStatus`, `VerificationFile`, `VerificationToken`, `DeviceID`, `CurrentAccessCode`, `StationID`, `LastLatitude`, `LastLongitude`, `LastAddress`, `LastLocationUrl`, `LastLocationUpdatedAt`, `Last_Login`)
VALUES
(1, 'Princess Maria', NULL, 'shaheenmariaa@gmail.com', SHA2('change_me_admin_1',256), '0795279261', NULL, 'Admin', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'Queen Hind', NULL, 'hiinnd.ali03@gmail.com', SHA2('change_me_admin_2',256), '0790530063', NULL, 'Admin', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

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

-- Demo seed data: realistic Clevo system snapshot for Amman.
-- Password for all demo accounts: 123456
INSERT INTO `user`
(`UserID`, `UserName`, `ProfilePic`, `Email`, `Password`, `PhoneNumber`, `CarName`, `Role`, `AccountStatus`, `VerificationFile`, `VerificationToken`, `DeviceID`, `CurrentAccessCode`, `StationID`, `LastLatitude`, `LastLongitude`, `LastAddress`, `LastLocationUrl`, `LastLocationUpdatedAt`, `Last_Login`)
VALUES
(3, 'Ahmad Naser', NULL, 'owner.abdoun@clevo.test', SHA2('123456',256), '0791000003', NULL, 'owner', 'active', 'uploads/verification/verify_1777057576_69ebbf2832d5a.jpg', NULL, 'OWN-AMM-003', NULL, 1, NULL, NULL, NULL, NULL, NULL, '2026-06-01 08:15:00'),
(4, 'Leen Haddad', NULL, 'owner.sweifieh@clevo.test', SHA2('123456',256), '0791000004', NULL, 'owner', 'active', 'uploads/verification/verify_1777236018_69ee7832b1aea.jpg', NULL, 'OWN-AMM-004', NULL, 2, NULL, NULL, NULL, NULL, NULL, '2026-06-01 09:05:00'),
(5, 'Omar Qasem', NULL, 'owner.khalda@clevo.test', SHA2('123456',256), '0791000005', NULL, 'owner', 'active', 'uploads/verification/verify_1777761128_69f67b6881d76.png', NULL, 'OWN-AMM-005', NULL, 3, NULL, NULL, NULL, NULL, NULL, '2026-06-01 10:45:00'),
(6, 'Maya Saleh', NULL, 'owner.jubeiha@clevo.test', SHA2('123456',256), '0791000006', NULL, 'owner', 'active', 'uploads/verification/verify_1777837475_69f7a5a3e88b9.png', NULL, 'OWN-AMM-006', NULL, 4, NULL, NULL, NULL, NULL, NULL, '2026-06-01 11:30:00'),
(7, 'Yazan Khatib', NULL, 'owner.marj@clevo.test', SHA2('123456',256), '0791000007', NULL, 'owner', 'active', 'uploads/verification/verify_1777911605_69f8c735c50e5.jpeg', NULL, 'OWN-AMM-007', NULL, 5, NULL, NULL, NULL, NULL, NULL, '2026-06-01 12:10:00'),
(8, 'Dana Rawashdeh', NULL, 'owner.tabarbour@clevo.test', SHA2('123456',256), '0791000008', NULL, 'owner', 'active', 'uploads/verification/verify_1777913378_69f8ce226a00e.png', NULL, 'OWN-AMM-008', NULL, 6, NULL, NULL, NULL, NULL, NULL, '2026-06-01 13:20:00'),
(9, 'Faris Alami', NULL, 'owner.tlaali@clevo.test', SHA2('123456',256), '0791000009', NULL, 'owner', 'active', 'uploads/verification/verify_1777914335_69f8d1df41a99.png', NULL, 'OWN-AMM-009', NULL, 7, NULL, NULL, NULL, NULL, NULL, '2026-06-01 14:00:00'),
(10, 'Rama Darwish', NULL, 'owner.dabouq@clevo.test', SHA2('123456',256), '0791000010', NULL, 'owner', 'active', 'uploads/verification/verify_1777921083_69f8ec3b10513.jpeg', NULL, 'OWN-AMM-010', NULL, 8, NULL, NULL, NULL, NULL, NULL, '2026-06-01 14:35:00'),
(11, 'Sami Mustafa', NULL, 'sami.mustafa@clevo.test', SHA2('123456',256), '0791000011', 'Toyota Corolla 2020', 'User', 'active', NULL, NULL, 'CUS-AMM-011', NULL, NULL, 31.94920000, 35.87560000, 'Abdoun, Amman', 'https://www.google.com/maps?q=31.9492,35.8756', '2026-06-01 18:20:00', '2026-06-01 18:20:00'),
(12, 'Nour Ibrahim', NULL, 'nour.ibrahim@clevo.test', SHA2('123456',256), '0791000012', 'Kia Sportage 2022', 'User', 'active', NULL, NULL, 'CUS-AMM-012', NULL, NULL, 31.95870000, 35.86190000, 'Sweifieh, Amman', 'https://www.google.com/maps?q=31.9587,35.8619', '2026-06-01 19:05:00', '2026-06-01 19:05:00'),
(13, 'Khaled Mansour', NULL, 'khaled.mansour@clevo.test', SHA2('123456',256), '0791000013', 'Hyundai Elantra 2021', 'User', 'active', NULL, NULL, 'CUS-AMM-013', NULL, NULL, 31.99900000, 35.84390000, 'Khalda, Amman', 'https://www.google.com/maps?q=31.9990,35.8439', '2026-06-01 20:10:00', '2026-06-01 20:10:00'),
(14, 'Sara Abu Zaid', NULL, 'sara.abuzaid@clevo.test', SHA2('123456',256), '0791000014', 'Nissan Kicks 2023', 'User', 'active', NULL, NULL, 'CUS-AMM-014', NULL, NULL, 32.02450000, 35.87010000, 'Al Jubeiha, Amman', 'https://www.google.com/maps?q=32.0245,35.8701', '2026-06-01 21:15:00', '2026-06-01 21:15:00'),
(15, 'Tareq Shannak', NULL, 'tareq.shannak@clevo.test', SHA2('123456',256), '0791000015', 'BMW 320i 2019', 'User', 'active', NULL, NULL, 'CUS-AMM-015', NULL, NULL, 31.90140000, 35.84270000, 'Marj Al Hamam, Amman', 'https://www.google.com/maps?q=31.9014,35.8427', '2026-06-01 22:05:00', '2026-06-01 22:05:00'),
(16, 'Mahmoud Driver', NULL, 'driver.mahmoud@clevo.test', SHA2('123456',256), '0791000016', NULL, 'driver', 'active', NULL, NULL, 'DRV-AMM-016', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-01 16:25:00'),
(17, 'Razan Driver', NULL, 'driver.razan@clevo.test', SHA2('123456',256), '0791000017', NULL, 'driver', 'active', NULL, NULL, 'DRV-AMM-017', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-01 17:05:00');

INSERT INTO `stations`
(`StationID`, `UserID`, `Name`, `Location`, `Location_URL`, `Lat`, `Lon`, `PhoneNumber`, `Rating`, `Status`, `admin_status`, `CommercialRecordPath`, `Image`, `Has_At_Station`, `Has_Home_Service`, `Has_Delivery`, `OpeningTime`, `ClosingTime`)
VALUES
(1, 3, 'Clevo Abdoun Shine', 'Abdoun, Amman', 'https://maps.google.com/?q=31.9492,35.8756', 31.94920000, 35.87560000, '0792000001', 4.92, 'Active', 'approved', 'uploads/verification/verify_1777057576_69ebbf2832d5a.jpg', 'clevo-station-exterior.png', 1, 1, 1, '08:00:00', '23:00:00'),
(2, 4, 'Clevo Sweifieh Auto Spa', 'Sweifieh, Amman', 'https://maps.google.com/?q=31.9587,35.8619', 31.95870000, 35.86190000, '0792000002', 4.78, 'Active', 'approved', 'uploads/verification/verify_1777236018_69ee7832b1aea.jpg', 'clevo-station-tunnel.png', 1, 1, 0, '08:30:00', '22:30:00'),
(3, 5, 'Clevo Khalda Wash Hub', 'Khalda, Amman', 'https://maps.google.com/?q=31.9990,35.8439', 31.99900000, 35.84390000, '0792000003', 4.84, 'Active', 'approved', 'uploads/verification/verify_1777761128_69f67b6881d76.png', 'clevo-station-exterior.png', 1, 1, 1, '07:30:00', '22:00:00'),
(4, 6, 'Clevo Jubeiha Detail Center', 'Al Jubeiha, Amman', 'https://maps.google.com/?q=32.0245,35.8701', 32.02450000, 35.87010000, '0792000004', 4.69, 'Busy', 'approved', 'uploads/verification/verify_1777837475_69f7a5a3e88b9.png', 'clevo-station-detailing.png', 1, 0, 1, '09:00:00', '21:30:00'),
(5, 7, 'Clevo Marj Al Hamam Care', 'Marj Al Hamam, Amman', 'https://maps.google.com/?q=31.9014,35.8427', 31.90140000, 35.84270000, '0792000005', 4.88, 'Active', 'approved', 'uploads/verification/verify_1777911605_69f8c735c50e5.jpeg', 'clevo-station-home-service.png', 1, 1, 1, '08:00:00', '22:00:00'),
(6, 8, 'Clevo Tabarbour Express', 'Tabarbour, Amman', 'https://maps.google.com/?q=31.9957,35.9458', 31.99570000, 35.94580000, '0792000006', 4.55, 'Active', 'approved', 'uploads/verification/verify_1777913378_69f8ce226a00e.png', 'clevo-station-home-service.png', 1, 0, 1, '08:00:00', '23:30:00'),
(7, 9, 'Clevo Tla Al Ali Premium', 'Tla Al Ali, Amman', 'https://maps.google.com/?q=31.9926,35.8613', 31.99260000, 35.86130000, '0792000007', 4.73, 'Active', 'approved', 'uploads/verification/verify_1777914335_69f8d1df41a99.png', 'clevo-station-detailing.png', 1, 1, 0, '08:30:00', '22:00:00'),
(8, 10, 'Clevo Dabouq Elite Wash', 'Dabouq, Amman', 'https://maps.google.com/?q=31.9998,35.8069', 31.99980000, 35.80690000, '0792000008', 4.96, 'Active', 'approved', 'uploads/verification/verify_1777921083_69f8ec3b10513.jpeg', 'clevo-station-tunnel.png', 1, 1, 1, '07:00:00', '23:00:00');

INSERT INTO `services`
(`ServiceID`, `StationID`, `Name`, `Name_ar`, `Name_en`, `Description`, `Description_ar`, `Description_en`, `Price`, `Duration`, `is_station`, `is_home`, `is_pickup`)
VALUES
(1, 1, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Interior and exterior wash with tire polish.', 'غسيل داخلي وخارجي مع تلميع الإطارات.', 'Interior and exterior wash with tire polish.', 9.00, 45, 1, 1, 1),
(2, 1, 'Interior Deep Clean', 'تنظيف داخلي عميق', 'Interior Deep Clean', 'Seats, dashboard, mats, and cabin sanitizing.', 'تنظيف المقاعد والتابلوه والدواسات وتعقيم المقصورة.', 'Seats, dashboard, mats, and cabin sanitizing.', 7.50, 40, 1, 1, 1),
(3, 1, 'Exterior Polish', 'تلميع خارجي', 'Exterior Polish', 'Foam wash, wax layer, and paint shine.', 'غسيل رغوي وطبقة شمع وتلميع للدهان.', 'Foam wash, wax layer, and paint shine.', 12.00, 55, 1, 0, 1),
(4, 1, 'Oil Change', 'تغيير زيت', 'Oil Change', 'Engine oil replacement with basic checkup.', 'تغيير زيت المحرك مع فحص أساسي.', 'Engine oil replacement with basic checkup.', 22.00, 35, 1, 0, 0),
(5, 2, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Complete wash using premium shampoo.', 'غسيل كامل باستخدام شامبو ممتاز.', 'Complete wash using premium shampoo.', 8.50, 40, 1, 1, 0),
(6, 2, 'Interior Wash', 'غسيل داخلي', 'Interior Wash', 'Cabin vacuum, surfaces, and fragrance.', 'شفط وتنظيف أسطح المقصورة مع تعطير.', 'Cabin vacuum, surfaces, and fragrance.', 6.50, 35, 1, 1, 0),
(7, 2, 'Exterior Wash', 'غسيل خارجي', 'Exterior Wash', 'Body wash, wheels, and quick wax.', 'غسيل الهيكل والعجلات مع شمع سريع.', 'Body wash, wheels, and quick wax.', 5.50, 25, 1, 0, 0),
(8, 2, 'Ceramic Quick Coat', 'طبقة سيراميك سريعة', 'Ceramic Quick Coat', 'Light protective ceramic spray.', 'رذاذ سيراميك خفيف للحماية.', 'Light protective ceramic spray.', 16.00, 60, 1, 0, 0),
(9, 3, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Fast full service for daily use cars.', 'خدمة كاملة وسريعة لسيارات الاستخدام اليومي.', 'Fast full service for daily use cars.', 8.00, 35, 1, 1, 1),
(10, 3, 'Interior Detailing', 'تنظيف داخلي تفصيلي', 'Interior Detailing', 'Deep fabric and plastic cleaning.', 'تنظيف عميق للأقمشة والبلاستيك.', 'Deep fabric and plastic cleaning.', 13.00, 70, 1, 1, 1),
(11, 3, 'Exterior Wash', 'غسيل خارجي', 'Exterior Wash', 'Foam, rinse, dry, and wheel clean.', 'رغوة وشطف وتجفيف وتنظيف العجلات.', 'Foam, rinse, dry, and wheel clean.', 5.00, 25, 1, 0, 1),
(12, 3, 'Oil Change', 'تغيير زيت', 'Oil Change', 'Oil and filter change service.', 'خدمة تغيير الزيت والفلتر.', 'Oil and filter change service.', 24.00, 40, 1, 0, 0),
(13, 4, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Detailed inside and outside wash.', 'غسيل تفصيلي من الداخل والخارج.', 'Detailed inside and outside wash.', 9.50, 50, 1, 0, 1),
(14, 4, 'Interior Steam Clean', 'تنظيف داخلي بالبخار', 'Interior Steam Clean', 'Steam clean and odor treatment.', 'تنظيف بالبخار ومعالجة الروائح.', 'Steam clean and odor treatment.', 15.00, 80, 1, 0, 1),
(15, 4, 'Exterior Polish', 'تلميع خارجي', 'Exterior Polish', 'Premium polish and wax finish.', 'تلميع ممتاز مع طبقة شمع نهائية.', 'Premium polish and wax finish.', 18.00, 75, 1, 0, 1),
(16, 4, 'Headlight Restoration', 'تجديد المصابيح', 'Headlight Restoration', 'Headlight cleaning and shine restoration.', 'تنظيف المصابيح واستعادة لمعانها.', 'Headlight cleaning and shine restoration.', 10.00, 45, 1, 0, 0),
(17, 5, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Complete wash at station or customer location.', 'غسيل كامل في المحطة أو عند العميل.', 'Complete wash at station or customer location.', 8.75, 40, 1, 1, 1),
(18, 5, 'Home Interior Wash', 'غسيل داخلي منزلي', 'Home Interior Wash', 'Mobile cabin cleaning service.', 'خدمة تنظيف داخلية متنقلة.', 'Mobile cabin cleaning service.', 9.00, 45, 0, 1, 1),
(19, 5, 'Exterior Wash', 'غسيل خارجي', 'Exterior Wash', 'Fast exterior wash and tire shine.', 'غسيل خارجي سريع مع تلميع الإطارات.', 'Fast exterior wash and tire shine.', 5.50, 25, 1, 1, 1),
(20, 5, 'Oil Change', 'تغيير زيت', 'Oil Change', 'Oil change with fluid level inspection.', 'تغيير زيت مع فحص مستويات السوائل.', 'Oil change with fluid level inspection.', 23.00, 35, 1, 0, 0),
(21, 6, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Express full car wash.', 'غسيل سيارة شامل وسريع.', 'Express full car wash.', 7.75, 35, 1, 0, 1),
(22, 6, 'Pickup Full Service', 'خدمة كاملة مع استلام وتسليم', 'Pickup Full Service', 'Pickup and drop full wash package.', 'باقة غسيل كاملة مع الاستلام والتسليم.', 'Pickup and drop full wash package.', 12.50, 60, 0, 0, 1),
(23, 6, 'Exterior Wash', 'غسيل خارجي', 'Exterior Wash', 'Quick exterior shine.', 'لمعة خارجية سريعة.', 'Quick exterior shine.', 4.75, 20, 1, 0, 1),
(24, 6, 'Premium Wax', 'شمع ممتاز', 'Premium Wax', 'Protective wax and gloss finish.', 'شمع حماية ولمعة نهائية.', 'Protective wax and gloss finish.', 13.50, 50, 1, 0, 1),
(25, 7, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Full car wash with dashboard care.', 'غسيل شامل مع عناية بالتابلوه.', 'Full car wash with dashboard care.', 8.25, 40, 1, 1, 0),
(26, 7, 'Interior Wash', 'غسيل داخلي', 'Interior Wash', 'Interior vacuum and wipe down.', 'شفط داخلي ومسح للأسطح.', 'Interior vacuum and wipe down.', 6.75, 30, 1, 1, 0),
(27, 7, 'Exterior Wash', 'غسيل خارجي', 'Exterior Wash', 'Body and wheel clean.', 'تنظيف الهيكل والعجلات.', 'Body and wheel clean.', 5.25, 25, 1, 0, 0),
(28, 7, 'Oil Change', 'تغيير زيت', 'Oil Change', 'Synthetic oil option available.', 'يتوفر خيار الزيت الصناعي.', 'Synthetic oil option available.', 25.00, 40, 1, 0, 0),
(29, 8, 'Full Wash', 'غسيل شامل', 'Full Wash', 'Elite full wash and finishing.', 'غسيل شامل فاخر مع تشطيب نهائي.', 'Elite full wash and finishing.', 10.00, 45, 1, 1, 1),
(30, 8, 'Interior Detailing', 'تنظيف داخلي تفصيلي', 'Interior Detailing', 'Premium cabin detailing package.', 'باقة تنظيف داخلي تفصيلية ممتازة.', 'Premium cabin detailing package.', 14.50, 75, 1, 1, 1),
(31, 8, 'Exterior Polish', 'تلميع خارجي', 'Exterior Polish', 'Paint-safe polish and wax.', 'تلميع آمن للدهان مع شمع.', 'Paint-safe polish and wax.', 19.00, 80, 1, 0, 1),
(32, 8, 'Oil Change', 'تغيير زيت', 'Oil Change', 'Oil, filter, and 10-point check.', 'زيت وفلتر وفحص من 10 نقاط.', 'Oil, filter, and 10-point check.', 26.00, 45, 1, 0, 0);

INSERT INTO `plans`
(`id`, `name_ar`, `name_en`, `price`, `duration`, `duration_type`, `wash_count`, `description_ar`, `description_en`, `included_services_ar`, `included_services_en`, `is_active`, `created_at`)
VALUES
(1, 'باقة المدينة', 'City Wash Plan', 24.00, 1, 'month', 4, 'أربع غسلات شهرية للاستخدام اليومي.', 'Four monthly washes for daily car care.', 'غسيل شامل,غسيل خارجي,تعطير داخلي', 'Full wash,Exterior wash,Interior fragrance', 1, '2026-05-01 09:00:00'),
(2, 'باقة بريميوم', 'Premium Care Plan', 42.00, 1, 'month', 6, 'غسلات أكثر مع خصومات على التلميع.', 'More washes with detailing discounts.', 'غسيل شامل,غسيل داخلي,خصم تلميع', 'Full wash,Interior wash,Polish discount', 1, '2026-05-01 09:10:00'),
(3, 'باقة العائلة', 'Family Cars Plan', 70.00, 1, 'month', 10, 'مناسبة لأكثر من سيارة في نفس البيت.', 'Made for multiple family cars.', 'غسيل شامل,خدمة منزلية,استلام وتسليم', 'Full wash,Home service,Pickup and drop', 1, '2026-05-01 09:20:00');

INSERT INTO `user_plans`
(`id`, `user_id`, `plan_id`, `start_date`, `end_date`, `status`, `remaining_washes`, `created_at`)
VALUES
(1, 11, 2, '2026-05-20', '2026-06-20', 'active', 3, '2026-05-20 12:00:00'),
(2, 12, 1, '2026-05-25', '2026-06-25', 'active', 2, '2026-05-25 14:30:00'),
(3, 14, 3, '2026-05-18', '2026-06-18', 'active', 7, '2026-05-18 10:15:00'),
(4, 15, 1, '2026-04-15', '2026-05-15', 'expired', 0, '2026-04-15 11:45:00');

INSERT INTO `booking`
(`BookingID`, `Order_Number`, `UserID`, `StationID`, `ServiceID`, `DriverID`, `Service_Location`, `Latitude`, `Longitude`, `Driver_Lat`, `Driver_Lon`, `Address_Details`, `Booking_Date`, `Booking_Time`, `Status`, `Total_Price`, `Admin_Commission`, `Payment_Method`, `Points_Used`, `Points_Discount`, `Payable_Amount`, `UserPlanID`, `Plan_Washes_Used`, `Accepted_At`, `notification_sent`, `created_at`)
VALUES
(1, 'C260520090101', 11, 1, 1, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Full Wash', '2026-05-20', '09:00:00', 'Completed', 9.00, 1.35, 'cash', 0.00, 0.00, 9.00, NULL, 0, '2026-05-19 18:00:00', 1, '2026-05-19 17:40:00'),
(2, 'C260521140222', 12, 2, 5, NULL, 'home', 31.95700000, 35.86500000, NULL, NULL, 'Sweifieh - near Avenue Mall\nServices: Full Wash', '2026-05-21', '14:00:00', 'Completed', 8.50, 1.28, 'wallet', 80.00, 2.00, 6.50, NULL, 0, '2026-05-20 16:00:00', 1, '2026-05-20 15:30:00'),
(3, 'C260522110333', 13, 3, 10, 16, 'delivery', 32.00000000, 35.84520000, 31.99910000, 35.84400000, 'Khalda - Wasfi Al Tal Street\nServices: Interior Detailing', '2026-05-22', '11:00:00', 'Completed', 13.00, 1.95, 'visa', 0.00, 0.00, 13.00, NULL, 0, '2026-05-21 12:20:00', 1, '2026-05-21 11:55:00'),
(4, 'C260523163044', 14, 5, 17, NULL, 'home', 31.90310000, 35.84190000, NULL, NULL, 'Marj Al Hamam - building 18\nServices: Full Wash', '2026-05-23', '16:30:00', 'Completed', 8.75, 1.31, 'plan', 0.00, 0.00, 0.00, 3, 1, '2026-05-22 18:10:00', 1, '2026-05-22 17:50:00'),
(5, 'C260524100555', 15, 8, 29, 17, 'delivery', 32.00100000, 35.80800000, 32.00020000, 35.80740000, 'Dabouq - villa gate 4\nServices: Full Wash', '2026-05-24', '10:00:00', 'Completed', 10.00, 1.50, 'wallet', 0.00, 0.00, 10.00, NULL, 0, '2026-05-23 14:00:00', 1, '2026-05-23 13:35:00'),
(6, 'C260525130606', 11, 7, 28, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Oil Change', '2026-05-25', '13:00:00', 'Completed', 25.00, 3.75, 'visa', 200.00, 5.00, 20.00, NULL, 0, '2026-05-24 15:30:00', 1, '2026-05-24 15:00:00'),
(7, 'C260526180707', 12, 6, 22, 16, 'delivery', 31.99490000, 35.94460000, 31.99610000, 35.94510000, 'Tabarbour - opposite mosque\nServices: Pickup Full Service', '2026-05-26', '18:00:00', 'Accepted', 12.50, 1.88, 'visa', 0.00, 0.00, 12.50, NULL, 0, '2026-05-25 19:00:00', 1, '2026-05-25 18:20:00'),
(8, 'C260527120808', 13, 1, 2, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Interior Deep Clean', '2026-05-27', '12:00:00', 'Accepted', 7.50, 1.13, 'cash', 0.00, 0.00, 7.50, NULL, 0, '2026-05-26 10:30:00', 1, '2026-05-26 10:00:00'),
(9, 'C260528150909', 14, 3, 9, NULL, 'home', 31.99820000, 35.84610000, NULL, NULL, 'Khalda - apartment 22\nServices: Full Wash', '2026-05-28', '15:00:00', 'In Progress', 8.00, 1.20, 'plan', 0.00, 0.00, 0.00, 3, 1, '2026-05-27 11:15:00', 1, '2026-05-27 10:45:00'),
(10, 'C260529090010', 15, 2, 7, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Exterior Wash', '2026-05-29', '09:00:00', 'Pending', 5.50, 0.83, NULL, 0.00, 0.00, 5.50, NULL, 0, NULL, 0, '2026-05-28 18:00:00'),
(11, 'C260530170011', 11, 8, 30, 17, 'delivery', 31.95380000, 35.87490000, 31.97000000, 35.86000000, 'Abdoun - office parking\nServices: Interior Detailing', '2026-05-30', '17:00:00', 'Pickup', 14.50, 2.18, 'wallet', 0.00, 0.00, 14.50, NULL, 0, '2026-05-29 12:10:00', 1, '2026-05-29 11:45:00'),
(12, 'C260531193012', 12, 5, 19, NULL, 'home', 31.90250000, 35.84310000, NULL, NULL, 'Marj Al Hamam - beside supermarket\nServices: Exterior Wash', '2026-05-31', '19:30:00', 'Cancelled', 5.50, 0.83, NULL, 0.00, 0.00, 5.50, NULL, 0, NULL, 1, '2026-05-30 15:00:00'),
(13, 'C260601103013', 13, 4, 13, 16, 'delivery', 32.02380000, 35.87200000, 32.02290000, 35.87050000, 'Jubeiha - university street\nServices: Full Wash', '2026-06-01', '10:30:00', 'Returning', 9.50, 1.43, 'visa', 0.00, 0.00, 9.50, NULL, 0, '2026-05-31 16:00:00', 1, '2026-05-31 15:40:00'),
(14, 'C260602090014', 14, 1, 3, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Exterior Polish', '2026-06-02', '09:00:00', 'Pending', 12.00, 1.80, NULL, 0.00, 0.00, 12.00, NULL, 0, NULL, 0, '2026-06-01 18:15:00'),
(15, 'C260602133015', 15, 7, 25, NULL, 'home', 31.99150000, 35.86220000, NULL, NULL, 'Tla Al Ali - building 9\nServices: Full Wash', '2026-06-02', '13:30:00', 'Accepted', 8.25, 1.24, 'wallet', 0.00, 0.00, 8.25, NULL, 0, '2026-06-01 20:00:00', 1, '2026-06-01 19:20:00'),
(16, 'C260603113016', 11, 3, 12, NULL, 'station', NULL, NULL, NULL, NULL, 'Services: Oil Change', '2026-06-03', '11:30:00', 'Pending', 24.00, 3.60, NULL, 0.00, 0.00, 24.00, NULL, 0, NULL, 0, '2026-06-02 08:30:00'),
(17, 'C260604160017', 12, 8, 31, 17, 'delivery', 31.96050000, 35.86250000, NULL, NULL, 'Sweifieh - work address\nServices: Exterior Polish', '2026-06-04', '16:00:00', 'Accepted', 19.00, 2.85, 'visa', 120.00, 3.00, 16.00, NULL, 0, '2026-06-02 09:15:00', 1, '2026-06-02 08:55:00'),
(18, 'C260605100018', 13, 5, 18, NULL, 'home', 31.90400000, 35.84050000, NULL, NULL, 'Marj Al Hamam - garden entrance\nServices: Home Interior Wash', '2026-06-05', '10:00:00', 'Pending', 9.00, 1.35, NULL, 0.00, 0.00, 9.00, NULL, 0, NULL, 0, '2026-06-02 09:25:00');

UPDATE `booking` SET `DriverAccessCode` = '800007' WHERE `BookingID` = 7;
UPDATE `booking` SET `DriverAccessCode` = '800011' WHERE `BookingID` = 11;
UPDATE `booking` SET `DriverAccessCode` = '800013' WHERE `BookingID` = 13;
UPDATE `booking` SET `DriverAccessCode` = '800017' WHERE `BookingID` = 17;

INSERT INTO `booking_services`
(`BookingServiceID`, `BookingID`, `ServiceID`, `ServiceName`, `Price`)
VALUES
(1, 1, 1, 'Full Wash', 9.00),
(2, 2, 5, 'Full Wash', 8.50),
(3, 3, 10, 'Interior Detailing', 13.00),
(4, 4, 17, 'Full Wash', 8.75),
(5, 5, 29, 'Full Wash', 10.00),
(6, 6, 28, 'Oil Change', 25.00),
(7, 7, 22, 'Pickup Full Service', 12.50),
(8, 8, 2, 'Interior Deep Clean', 7.50),
(9, 9, 9, 'Full Wash', 8.00),
(10, 10, 7, 'Exterior Wash', 5.50),
(11, 11, 30, 'Interior Detailing', 14.50),
(12, 12, 19, 'Exterior Wash', 5.50),
(13, 13, 13, 'Full Wash', 9.50),
(14, 14, 3, 'Exterior Polish', 12.00),
(15, 15, 25, 'Full Wash', 8.25),
(16, 16, 12, 'Oil Change', 24.00),
(17, 17, 31, 'Exterior Polish', 19.00),
(18, 18, 18, 'Home Interior Wash', 9.00);

INSERT INTO `reviews`
(`ReviewID`, `BookingID`, `Rating`, `Comment`, `Owner_Reply`)
VALUES
(1, 1, 5, 'Fast service and the car looked brand new.', 'Thank you for choosing Abdoun Shine.'),
(2, 2, 4, 'Good home service and polite team.', NULL),
(3, 3, 5, 'Pickup and drop were smooth.', 'Happy to serve you again.'),
(4, 4, 5, 'Plan booking worked perfectly.', NULL),
(5, 5, 5, 'Excellent cleaning and timing.', 'Appreciate your feedback.'),
(6, 6, 4, 'Oil change was quick and clear.', NULL);

INSERT INTO `loyalty`
(`LoyaltyID`, `UserID`, `PointsBalance`, `LastUpdated`)
VALUES
(1, 11, 420.00, '2026-06-01 18:20:00'),
(2, 12, 260.00, '2026-06-01 19:05:00'),
(3, 13, 195.00, '2026-06-01 20:10:00'),
(4, 14, 350.00, '2026-06-01 21:15:00'),
(5, 15, 125.00, '2026-06-01 22:05:00');

INSERT INTO `wallet`
(`WalletID`, `UserID`, `Balance`, `Last_Updated`)
VALUES
(1, 3, 7.65, '2026-06-01 12:10:00'),
(2, 4, 7.22, '2026-06-01 12:15:00'),
(3, 5, 19.85, '2026-06-01 12:20:00'),
(4, 6, 8.07, '2026-06-01 12:25:00'),
(5, 7, 12.11, '2026-06-01 12:30:00'),
(6, 8, 10.62, '2026-06-01 12:35:00'),
(7, 9, 28.46, '2026-06-01 12:40:00'),
(8, 10, 22.72, '2026-06-01 12:45:00'),
(9, 11, 61.50, '2026-06-01 18:20:00'),
(10, 12, 43.50, '2026-06-01 19:05:00'),
(11, 13, 30.00, '2026-06-01 20:10:00'),
(12, 14, 75.00, '2026-06-01 21:15:00'),
(13, 15, 37.75, '2026-06-01 22:05:00'),
(14, 16, 0.00, '2026-06-01 16:25:00'),
(15, 17, 0.00, '2026-06-01 17:05:00');

INSERT INTO `wallet_transactions`
(`TransactionID`, `WalletID`, `Amount`, `TransactionType`, `Description_ar`, `Description_en`, `CreatedAt`)
VALUES
(1, 9, 75.00, 'credit', 'شحن محفظة Clevo', 'Clevo wallet top-up', '2026-05-18 12:00:00'),
(2, 10, 50.00, 'credit', 'شحن محفظة Clevo', 'Clevo wallet top-up', '2026-05-19 13:30:00'),
(3, 13, 55.00, 'credit', 'شحن محفظة Clevo', 'Clevo wallet top-up', '2026-05-20 14:10:00'),
(4, 10, 6.50, 'debit', 'دفع حجز #2', 'Booking payment #2', '2026-05-20 16:10:00'),
(5, 13, 10.00, 'debit', 'دفع حجز #5', 'Booking payment #5', '2026-05-23 14:05:00'),
(6, 9, 14.50, 'debit', 'دفع حجز #11', 'Booking payment #11', '2026-05-29 12:15:00'),
(7, 13, 8.25, 'debit', 'دفع حجز #15', 'Booking payment #15', '2026-06-01 20:05:00'),
(8, 1, 7.65, 'credit', 'إيراد صاحب محطة من الطلب #1', 'Owner booking earning #1', '2026-05-20 10:00:00'),
(9, 2, 7.22, 'credit', 'إيراد صاحب محطة من الطلب #2', 'Owner booking earning #2', '2026-05-21 15:10:00'),
(10, 3, 11.05, 'credit', 'إيراد صاحب محطة من الطلب #3', 'Owner booking earning #3', '2026-05-22 12:30:00'),
(11, 5, 7.44, 'credit', 'إيراد صاحب محطة من الطلب #4', 'Owner booking earning #4', '2026-05-23 17:20:00'),
(12, 8, 8.50, 'credit', 'إيراد صاحب محطة من الطلب #5', 'Owner booking earning #5', '2026-05-24 11:00:00'),
(13, 7, 21.25, 'credit', 'إيراد صاحب محطة من الطلب #6', 'Owner booking earning #6', '2026-05-25 14:10:00');

INSERT INTO `notifications`
(`id`, `user_id`, `target_role`, `title`, `title_en`, `body`, `body_en`, `target_url`, `type`, `is_read`, `created_at`)
VALUES
(1, 3, NULL, 'طلب جديد', 'New order', 'وصلك طلب #C260602090014 لخدمة Exterior Polish بتاريخ 2026-06-02 الساعة 09:00.', 'You received order #C260602090014 for Exterior Polish on 2026-06-02 at 09:00.', 'incoming-orders.php', 'info', 0, '2026-06-01 18:15:00'),
(2, 5, NULL, 'طلب جديد', 'New order', 'وصلك طلب #C260603113016 لخدمة Oil Change بتاريخ 2026-06-03 الساعة 11:30.', 'You received order #C260603113016 for Oil Change on 2026-06-03 at 11:30.', 'incoming-orders.php', 'info', 0, '2026-06-02 08:30:00'),
(3, 7, NULL, 'طلب جديد', 'New order', 'وصلك طلب #C260605100018 لخدمة Home Interior Wash بتاريخ 2026-06-05 الساعة 10:00.', 'You received order #C260605100018 for Home Interior Wash on 2026-06-05 at 10:00.', 'incoming-orders.php', 'info', 0, '2026-06-02 09:25:00'),
(4, 11, NULL, 'تم قبول الطلب', 'Order accepted', 'تم قبول طلبك #C260530170011 وجاري الاستلام.', 'Your order #C260530170011 was accepted and pickup is in progress.', 'order-details.php?id=11', 'success', 0, '2026-05-29 12:10:00'),
(5, 12, NULL, 'موعدك القادم', 'Upcoming appointment', 'طلبك #C260604160017 مقبول ومجدول ليوم 2026-06-04.', 'Your order #C260604160017 is accepted and scheduled for 2026-06-04.', 'order-details.php?id=17', 'info', 0, '2026-06-02 09:15:00'),
(6, NULL, 'Admin', 'نشاط تجريبي', 'Demo activity', 'تم تحميل بيانات تجريبية لمحطات عمان والحجوزات.', 'Demo data for Amman stations and bookings was loaded.', '../admin/admin-dashboard.php', 'success', 0, '2026-06-02 09:30:00');

INSERT INTO `support_tickets`
(`id`, `user_id`, `user_name`, `user_type`, `guest_email`, `subject`, `message`, `status`, `is_guest`, `created_at`)
VALUES
(1, 11, 'Sami Mustafa', 'user', NULL, 'Wallet top-up question', 'I want to know if wallet top-up appears immediately.', 'Replied', 0, '2026-05-28 11:00:00'),
(2, 4, 'Leen Haddad', 'owner', NULL, 'Station working hours', 'Please help me update weekend working hours.', 'New', 0, '2026-06-01 10:20:00'),
(3, NULL, 'Guest Visitor', 'user', 'guest.visitor@example.com', 'Service areas', 'Do you support home wash in all Amman areas?', 'New', 1, '2026-06-01 22:10:00');

COMMIT;
 
