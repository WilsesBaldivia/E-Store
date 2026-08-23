-- CSCQC E-Store database schema
-- Compatible with MariaDB 10.4+ / MySQL 8 where noted.
-- This script upgrades the original users table and creates the remaining tables.
-- It does not insert student accounts or a default administrator password.

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `e_storedb`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `e_storedb`;

-- -----------------------------------------------------------------------------
-- Users and authentication
-- -----------------------------------------------------------------------------

-- Correct structure for a new installation.
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `student_id` varchar(30) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Store only password_hash() output',
  `academic_level` enum('college','shs','jhs') DEFAULT NULL,
  `role` enum('student','staff','admin') NOT NULL DEFAULT 'student',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_student_id` (`student_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_level` (`academic_level`),
  KEY `idx_users_role_status` (`role`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe upgrade steps for the legacy users table from the original export.
ALTER TABLE `users`
  MODIFY COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN `first_name` varchar(50) NOT NULL,
  MODIFY COLUMN `last_name` varchar(50) NOT NULL,
  MODIFY COLUMN `email` varchar(150) NOT NULL,
  MODIFY COLUMN `password` varchar(255) NOT NULL COMMENT 'Store only password_hash() output',
  MODIFY COLUMN `role` enum('student','staff','admin') NOT NULL DEFAULT 'student',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  ADD COLUMN IF NOT EXISTS `student_id` varchar(30) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `academic_level` enum('college','shs','jhs') DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `status` enum('active','suspended') NOT NULL DEFAULT 'active' AFTER `role`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `users`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `users` SET `status` = 'active' WHERE `status` = 'pending';

ALTER TABLE `users`
  MODIFY COLUMN `status` enum('active','suspended') NOT NULL DEFAULT 'active';

-- Replace the old non-unique email index with account-level uniqueness.
ALTER TABLE `users` DROP INDEX IF EXISTS `email`;
CREATE UNIQUE INDEX IF NOT EXISTS `uq_users_email` ON `users` (`email`);
CREATE UNIQUE INDEX IF NOT EXISTS `uq_users_student_id` ON `users` (`student_id`);
CREATE INDEX IF NOT EXISTS `idx_users_level` ON `users` (`academic_level`);
CREATE INDEX IF NOT EXISTS `idx_users_role_status` ON `users` (`role`,`status`);

-- -----------------------------------------------------------------------------
-- Product catalog and inventory
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `categories` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`),
  UNIQUE KEY `uq_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` smallint unsigned NOT NULL,
  `sku` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `academic_level` enum('all','college','shs','jhs') NOT NULL DEFAULT 'all',
  `base_price` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_level_active` (`academic_level`,`is_active`),
  CONSTRAINT `fk_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `variant_sku` varchar(60) NOT NULL,
  `size` varchar(30) DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `price_override` decimal(10,2) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_variants_sku` (`variant_sku`),
  KEY `idx_product_variants_product` (`product_id`),
  CONSTRAINT `fk_product_variants_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `variant_id` int unsigned NOT NULL,
  `stock_quantity` int unsigned NOT NULL DEFAULT 0,
  `reserved_quantity` int unsigned NOT NULL DEFAULT 0,
  `reorder_level` int unsigned NOT NULL DEFAULT 5,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_variant` (`variant_id`),
  KEY `idx_inventory_stock` (`stock_quantity`,`reorder_level`),
  CONSTRAINT `fk_inventory_variant`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Reservations and order history
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reservations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reservation_code` varchar(30) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `status` enum('pending','processing','ready','claimed','cancelled') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
  `preferred_claim_date` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservations_code` (`reservation_code`),
  KEY `idx_reservations_user` (`user_id`),
  KEY `idx_reservations_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_reservations_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservation_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint unsigned NOT NULL,
  `variant_id` int unsigned DEFAULT NULL,
  `product_name` varchar(120) NOT NULL COMMENT 'Snapshot retained for order history',
  `variant_name` varchar(80) DEFAULT NULL COMMENT 'Size/color snapshot',
  `unit_price` decimal(10,2) unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reservation_items_reservation` (`reservation_id`),
  KEY `idx_reservation_items_variant` (`variant_id`),
  CONSTRAINT `fk_reservation_items_reservation`
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_reservation_items_variant`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Academic announcements
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `content` text NOT NULL,
  `academic_level` enum('all','college','shs','jhs') NOT NULL DEFAULT 'all',
  `announcement_type` enum('general','stock','schedule','urgent') NOT NULL DEFAULT 'general',
  `created_by` int unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_announcements_level_active` (`academic_level`,`is_active`,`published_at`),
  KEY `idx_announcements_created_by` (`created_by`),
  CONSTRAINT `fk_announcements_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Inventory audit trail
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `variant_id` int unsigned NOT NULL,
  `reservation_id` bigint unsigned DEFAULT NULL,
  `performed_by` int unsigned DEFAULT NULL,
  `movement_type` enum('stock_in','reserve','release','claim','adjustment') NOT NULL,
  `quantity_change` int NOT NULL COMMENT 'Positive adds stock; negative removes stock',
  `quantity_after` int unsigned NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_movements_variant_date` (`variant_id`,`created_at`),
  KEY `idx_inventory_movements_reservation` (`reservation_id`),
  KEY `idx_inventory_movements_staff` (`performed_by`),
  CONSTRAINT `fk_inventory_movements_variant`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_inventory_movements_reservation`
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_movements_staff`
    FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed only non-sensitive lookup data.
INSERT INTO `categories` (`name`, `slug`, `description`) VALUES
  ('Uniforms', 'uniforms', 'Official CSCQC uniforms for College, SHS, and JHS'),
  ('Books', 'books', 'Books, modules, and other learning materials')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

COMMIT;
