SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `clevo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `clevo`;

-- Make import idempotent: drop tables in safe order, then recreate
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `wallet_transactions`;
DROP TABLE IF EXISTS `wallet`;
DROP TABLE IF EXISTS `support_tickets`;
DROP TABLE IF EXISTS `user_plans`;
DROP TABLE IF EXISTS `plans`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `loyalty`;
DROP TABLE IF EXISTS `reviews`;
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
  `Last_Login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `PhoneNumber` (`PhoneNumber`),
  UNIQUE KEY `Email` (`Email`),
  UNIQUE KEY `VerificationToken` (`VerificationToken`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=3;

-- NOTE: sample users below set `Password` to a SHA2 placeholder; change these during setup.
INSERT INTO `user`
(`UserID`, `UserName`, `ProfilePic`, `Email`, `Password`, `PhoneNumber`, `CarName`, `Role`, `AccountStatus`, `VerificationFile`, `VerificationToken`, `DeviceID`, `CurrentAccessCode`, `StationID`, `Last_Login`)
VALUES
(1, 'Princess Maria', NULL, 'shaheenmariaa@gmail.com', SHA2('change_me_admin_1',256), '0795279261', NULL, 'Admin', 'active', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'Queen Hind', NULL, 'hiinnd.ali03@gmail.com', SHA2('change_me_admin_2',256), '0790530063', NULL, 'Admin', 'active', NULL, NULL, NULL, NULL, NULL, NULL);

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
  `Description` text DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT NULL,
  `Duration` int(11) DEFAULT NULL,
  `is_station` tinyint(1) DEFAULT 1,
  `is_home` tinyint(1) DEFAULT 0,
  `is_pickup` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`ServiceID`),
  KEY `StationID` (`StationID`),
  CONSTRAINT `services_ibfk_1` FOREIGN KEY (`StationID`) REFERENCES `stations` (`StationID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking` (
  `BookingID` int(11) NOT NULL AUTO_INCREMENT,
  `Order_Number` varchar(20) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `StationID` int(11) DEFAULT NULL,
  `ServiceID` int(11) DEFAULT NULL,
  `DriverID` int(11) DEFAULT NULL,
  `Service_Location` enum('station','home','delivery') DEFAULT 'station',
  `Latitude` decimal(10,8) DEFAULT NULL,
  `Longitude` decimal(11,8) DEFAULT NULL,
  `Driver_Lat` decimal(10,8) DEFAULT NULL,
  `Driver_Lon` decimal(11,8) DEFAULT NULL,
  `Address_Details` text DEFAULT NULL,
  `Booking_Date` date DEFAULT NULL,
  `Booking_Time` time DEFAULT NULL,
  `Status` enum('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled_Timeout','Completed') DEFAULT 'Pending',
  `Total_Price` decimal(10,2) DEFAULT NULL,
  `Admin_Commission` decimal(10,2) DEFAULT NULL,
  `Payment_Method` varchar(50) DEFAULT NULL,
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
  CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`StationID`) REFERENCES `stations` (`StationID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_3` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE CASCADE,
  CONSTRAINT `booking_ibfk_4` FOREIGN KEY (`DriverID`) REFERENCES `user` (`UserID`) ON DELETE SET NULL
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
  `PointsBalance` int(11) DEFAULT 0,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`LoyaltyID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `loyalty_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `type` enum('info','success','warning','danger') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
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

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `user_type` enum('user','owner','driver') DEFAULT 'user',
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('New','Replied','Closed') DEFAULT 'New',
  `is_guest` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`UserID`) ON DELETE SET NULL
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

COMMIT;
