-- Home 2 Home database schema
-- Source: database-schema.html (22 tables). Engine: InnoDB.
-- Uses utf8mb4_unicode_ci for compatibility with older MySQL/MariaDB servers.
-- Email uniqueness is case- and accent-insensitive with this collation.
-- CHECK enforcement and JSON support depend on the server version.
-- Initial schema for a new database; existing tables are never dropped.
-- Execute this file in MySQL Workbench, or from the mysql client:
--   SOURCE D:/dan/WebApp_Project/database-schema.sql;
-- Every application connection must also set time_zone = '+00:00'.
-- DATETIME(6) values are supplied by the application in UTC.
-- No sample accounts, roles or policy values are inserted.
CREATE DATABASE IF NOT EXISTS home_2_home
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE home_2_home;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION time_zone = '+00:00';



-- Identity: Shared account and editable profile for Guests, Hosts and Admins.
-- Rules: CHECK status in the documented set. Email is never used as a foreign key.
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Generated account ID',
  `email` VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Case-insensitive login email',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Argon2id/bcrypt hash',
  `full_name` VARCHAR(150) NOT NULL COMMENT 'Display name',
  `phone` VARCHAR(25) NULL COMMENT 'Contact number',
  `avatar_url` TEXT NULL COMMENT 'Profile image',
  `status` VARCHAR(12) NOT NULL COMMENT 'active | suspended | deleted',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Registration time',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'Last profile update',
  `deleted_at` DATETIME(6) NULL COMMENT 'Soft deletion time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  CONSTRAINT `chk_users_status` CHECK (status IN ('active','suspended','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity: Multiple roles per account; a Guest can also become a Host.
-- Rules: Composite PK (user_id, role); CHECK role IN (guest, host, admin).
CREATE TABLE `user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Account receiving the role',
  `role` VARCHAR(10) NOT NULL COMMENT 'guest | host | admin',
  `granted_at` DATETIME(6) NOT NULL COMMENT 'Role assignment time',
  PRIMARY KEY (`user_id`, `role`),
  CONSTRAINT `fk_user_roles_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_user_roles_role` CHECK (role IN ('guest','host','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity: Server-side login sessions and logout/revocation.
-- Rules: CHECK expires_at > created_at. Reject revoked, expired or suspended-account sessions.
CREATE TABLE `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Session identifier',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Authenticated account',
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Hash of the session secret',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Issued time',
  `expires_at` DATETIME(6) NOT NULL COMMENT 'Expiry time',
  `revoked_at` DATETIME(6) NULL COMMENT 'Logout or forced revocation',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token_hash` (`token_hash`),
  KEY `idx_sessions_user_id` (`user_id`),
  CONSTRAINT `fk_sessions_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_sessions_expiry` CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity: Forgot-password flow using expiring, single-use tokens.
-- Rules: CHECK expires_at > created_at. Consume with an atomic conditional update.
CREATE TABLE `password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Reset request ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Account to recover',
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Hashed recovery token',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Issue time',
  `expires_at` DATETIME(6) NOT NULL COMMENT 'Expiry time',
  `used_at` DATETIME(6) NULL COMMENT 'Consumption time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_tokens_token_hash` (`token_hash`),
  KEY `idx_password_reset_tokens_user_id` (`user_id`),
  CONSTRAINT `fk_password_reset_tokens_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_password_reset_tokens_expiry` CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Admin-managed accommodation types: entire home, private room, shared room.
-- Rules: Deactivate referenced types instead of removing historical classification.
CREATE TABLE `property_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Type ID',
  `name` VARCHAR(80) NOT NULL COMMENT 'Type label',
  `description` TEXT NULL COMMENT 'Category explanation',
  `is_active` TINYINT(1) NOT NULL COMMENT 'Whether available for new listings',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_types_name` (`name`),
  CONSTRAINT `chk_property_types_is_active` CHECK (`is_active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Reusable flexible/moderate/strict cancellation and refund rules.
-- Rules: CHECK cutoff_hours >= 0; percentages between 0 and 100. Hosts select a policy; snapshot its values into each booking.
CREATE TABLE `cancellation_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Policy ID',
  `name` VARCHAR(80) NOT NULL COMMENT 'Policy label',
  `description` TEXT NOT NULL COMMENT 'Guest-facing terms',
  `cutoff_hours` INTEGER NOT NULL COMMENT 'Hours before check-in separating refund tiers',
  `early_refund_pct` DECIMAL(5,2) NOT NULL COMMENT 'Night-subtotal percentage before cutoff',
  `late_refund_pct` DECIMAL(5,2) NOT NULL COMMENT 'Night-subtotal percentage after cutoff',
  `refund_fees` TINYINT(1) NOT NULL COMMENT 'Whether the fixed fee is refundable',
  `is_active` TINYINT(1) NOT NULL COMMENT 'Available for new listings',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cancellation_policies_name` (`name`),
  CONSTRAINT `chk_cancellation_policies_cutoff` CHECK (cutoff_hours >= 0),
  CONSTRAINT `chk_cancellation_policies_early_refund` CHECK (early_refund_pct BETWEEN 0 AND 100),
  CONSTRAINT `chk_cancellation_policies_late_refund` CHECK (late_refund_pct BETWEEN 0 AND 100),
  CONSTRAINT `chk_cancellation_policies_refund_fees` CHECK (`refund_fees` IN (0, 1)),
  CONSTRAINT `chk_cancellation_policies_is_active` CHECK (`is_active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Host accommodation, pricing, location, house rules and Admin approval.
-- Rules: CHECK nonnegative prices/counts, max_guests > 0; latitude −90…90 and longitude −180…180, both present or both absent. Public only if approved, visible and not deleted. Material edits return to pending.
CREATE TABLE `listings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Listing ID',
  `host_id` BIGINT UNSIGNED NOT NULL COMMENT 'Owner with Host role',
  `property_type_id` BIGINT UNSIGNED NOT NULL COMMENT 'Accommodation type',
  `cancellation_policy_id` BIGINT UNSIGNED NOT NULL COMMENT 'Current cancellation terms',
  `title` VARCHAR(200) NOT NULL COMMENT 'Listing title',
  `description` TEXT NOT NULL COMMENT 'Accommodation description',
  `address` TEXT NOT NULL COMMENT 'Street address',
  `city` VARCHAR(100) NOT NULL COMMENT 'Searchable city',
  `country_code` CHAR(2) NOT NULL COMMENT 'ISO country code',
  `latitude` DECIMAL(9,6) NULL COMMENT 'Map latitude',
  `longitude` DECIMAL(9,6) NULL COMMENT 'Map longitude',
  `time_zone` TEXT NOT NULL COMMENT 'IANA zone, e.g. Asia/Ho_Chi_Minh',
  `base_nightly_rate` DECIMAL(12,2) NOT NULL COMMENT 'Current price per night',
  `fee_amount` DECIMAL(12,2) NOT NULL COMMENT 'Fixed surcharge per stay',
  `currency` CHAR(3) NOT NULL COMMENT 'ISO currency, e.g. VND',
  `max_guests` INTEGER NOT NULL COMMENT 'Guest capacity',
  `room_count` INTEGER NOT NULL COMMENT 'Total rooms',
  `bedroom_count` INTEGER NOT NULL COMMENT 'Number of bedrooms',
  `bed_count` INTEGER NOT NULL COMMENT 'Number of beds',
  `check_in_time` TIME NOT NULL COMMENT 'Local check-in time',
  `check_out_time` TIME NOT NULL COMMENT 'Local check-out time',
  `smoking_allowed` TINYINT(1) NOT NULL COMMENT 'Smoking rule',
  `pets_allowed` TINYINT(1) NOT NULL COMMENT 'Pet rule',
  `parties_allowed` TINYINT(1) NOT NULL COMMENT 'Party rule',
  `house_rules` TEXT NULL COMMENT 'Additional rules',
  `moderation_status` VARCHAR(12) NOT NULL COMMENT 'draft | pending | approved | rejected',
  `moderation_reason` TEXT NULL COMMENT 'Reason for rejection or removal',
  `reviewed_by` BIGINT UNSIGNED NULL COMMENT 'Admin moderator',
  `reviewed_at` DATETIME(6) NULL COMMENT 'Moderation time',
  `is_visible` TINYINT(1) NOT NULL COMMENT 'Host/Admin visibility switch',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Creation time',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'Last edit',
  `deleted_at` DATETIME(6) NULL COMMENT 'Soft deletion time',
  PRIMARY KEY (`id`),
  KEY `idx_listings_moderation_status_is_visible_city_base_nightly_rate` (`moderation_status`, `is_visible`, `city`, `base_nightly_rate`),
  KEY `idx_listings_host_id` (`host_id`),
  KEY `idx_listings_property_type_id` (`property_type_id`),
  KEY `idx_listings_cancellation_policy_id` (`cancellation_policy_id`),
  KEY `idx_listings_reviewed_by` (`reviewed_by`),
  FULLTEXT KEY `ft_listings_title_description` (`title`, `description`),
  CONSTRAINT `fk_listings_host_id` FOREIGN KEY (`host_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_listings_property_type_id` FOREIGN KEY (`property_type_id`)
    REFERENCES `property_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_listings_cancellation_policy_id` FOREIGN KEY (`cancellation_policy_id`)
    REFERENCES `cancellation_policies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_listings_reviewed_by` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_listings_prices` CHECK (base_nightly_rate >= 0 AND fee_amount >= 0),
  CONSTRAINT `chk_listings_capacity` CHECK (max_guests > 0),
  CONSTRAINT `chk_listings_counts` CHECK (room_count >= 0 AND bedroom_count >= 0 AND bed_count >= 0),
  CONSTRAINT `chk_listings_coordinates` CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)),
  CONSTRAINT `chk_listings_moderation` CHECK (moderation_status IN ('draft','pending','approved','rejected')),
  CONSTRAINT `chk_listings_smoking_allowed` CHECK (`smoking_allowed` IN (0, 1)),
  CONSTRAINT `chk_listings_pets_allowed` CHECK (`pets_allowed` IN (0, 1)),
  CONSTRAINT `chk_listings_parties_allowed` CHECK (`parties_allowed` IN (0, 1)),
  CONSTRAINT `chk_listings_is_visible` CHECK (`is_visible` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Ordered accommodation images; the first image is the cover.
-- Rules: UQ (listing_id, sort_order); CHECK sort_order >= 0. Store image files outside the database.
CREATE TABLE `listing_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Photo ID',
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Photographed listing',
  `image_url` TEXT NOT NULL COMMENT 'Object storage URL/key',
  `alt_text` TEXT NULL COMMENT 'Accessible image description',
  `sort_order` INTEGER NOT NULL COMMENT 'Display position',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Upload time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_listing_photos_listing_id_sort_order` (`listing_id`, `sort_order`),
  CONSTRAINT `fk_listing_photos_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_listing_photos_sort_order` CHECK (sort_order >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Admin-managed searchable amenities such as Wi-Fi, kitchen and pool.
-- Rules: Pet permission is stored in listings.pets_allowed, avoiding a second conflicting rule.
CREATE TABLE `amenities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Amenity ID',
  `name` VARCHAR(80) NOT NULL COMMENT 'Amenity label',
  `icon_key` VARCHAR(80) NULL COMMENT 'UI icon identifier',
  `is_active` TINYINT(1) NOT NULL COMMENT 'Available for selection',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_amenities_name` (`name`),
  CONSTRAINT `chk_amenities_is_active` CHECK (`is_active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog: Many-to-many assignment of amenities to accommodations.
-- Rules: Composite PK (listing_id, amenity_id) prevents duplicate assignments.
CREATE TABLE `listing_amenities` (
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Accommodation',
  `amenity_id` BIGINT UNSIGNED NOT NULL COMMENT 'Provided amenity',
  PRIMARY KEY (`listing_id`, `amenity_id`),
  KEY `idx_listing_amenities_amenity_id` (`amenity_id`),
  CONSTRAINT `fk_listing_amenities_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_listing_amenities_amenity_id` FOREIGN KEY (`amenity_id`)
    REFERENCES `amenities` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking: Host-managed rental dates; booking occupancy is derived separately.
-- Rules: Composite PK (listing_id, stay_date). Missing or closed dates are unavailable. Open dates may still be occupied by confirmed bookings.
CREATE TABLE `listing_availability` (
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Rentable unit',
  `stay_date` DATE NOT NULL COMMENT 'Night in the listing local time zone',
  `is_open` TINYINT(1) NOT NULL COMMENT 'Host offers this night',
  `block_reason` TEXT NULL COMMENT 'Private note for a closed date',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'Last calendar edit',
  PRIMARY KEY (`listing_id`, `stay_date`),
  CONSTRAINT `fk_listing_availability_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_listing_availability_is_open` CHECK (`is_open` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking: Guest requests, lifecycle and immutable server-calculated quote snapshots.
-- Rules: CHECK check_out > check_in; guest_count > 0; amounts >= 0; CHECK status enum. Listing-row locking and transactional cross-table checks described below.
CREATE TABLE `bookings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Booking ID',
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Requested accommodation',
  `guest_id` BIGINT UNSIGNED NOT NULL COMMENT 'Booking Guest',
  `check_in` DATE NOT NULL COMMENT 'Arrival date',
  `check_out` DATE NOT NULL COMMENT 'Departure date (exclusive)',
  `guest_count` INTEGER NOT NULL COMMENT 'Number of guests',
  `status` VARCHAR(12) NOT NULL COMMENT 'pending | confirmed | rejected | cancelled | completed',
  `currency` CHAR(3) NOT NULL COMMENT 'Currency at booking time',
  `fee_amount` DECIMAL(12,2) NOT NULL COMMENT 'Per-stay surcharge snapshot',
  `total_amount` DECIMAL(12,2) NOT NULL COMMENT 'Sum of night prices plus surcharge',
  `policy_snapshot` JSON NOT NULL COMMENT 'Immutable full cancellation terms and local check-in context',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Request time',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'Last status update',
  PRIMARY KEY (`id`),
  KEY `idx_bookings_guest_id_created_at` (`guest_id`, `created_at`),
  KEY `idx_bookings_listing_id_status_check_in` (`listing_id`, `status`, `check_in`),
  CONSTRAINT `fk_bookings_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_guest_id` FOREIGN KEY (`guest_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_bookings_dates` CHECK (check_out > check_in),
  CONSTRAINT `chk_bookings_guest_count` CHECK (guest_count > 0),
  CONSTRAINT `chk_bookings_amounts` CHECK (fee_amount >= 0 AND total_amount >= 0),
  CONSTRAINT `chk_bookings_status` CHECK (status IN ('pending','confirmed','rejected','cancelled','completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking: One immutable nightly price snapshot for every booked night.
-- Rules: Composite PK (booking_id, stay_date); CHECK nightly_amount >= 0. Transaction checks dates exactly cover the booking interval.
CREATE TABLE `booking_nights` (
  `booking_id` BIGINT UNSIGNED NOT NULL COMMENT 'Quoted booking',
  `stay_date` DATE NOT NULL COMMENT 'Local night within the stay interval',
  `nightly_amount` DECIMAL(12,2) NOT NULL COMMENT 'Night price at request time',
  PRIMARY KEY (`booking_id`, `stay_date`),
  CONSTRAINT `fk_booking_nights_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_nights_amount` CHECK (nightly_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking: Append-only history of booking requests and status changes.
-- Rules: Validate both statuses and the documented state machine. Write atomically with the booking.
CREATE TABLE `booking_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Event ID',
  `booking_id` BIGINT UNSIGNED NOT NULL COMMENT 'Booking affected',
  `actor_id` BIGINT UNSIGNED NULL COMMENT 'Actor; NULL for automated system',
  `from_status` VARCHAR(12) NULL COMMENT 'NULL only for initial request',
  `to_status` VARCHAR(12) NOT NULL COMMENT 'New status',
  `reason` TEXT NULL COMMENT 'Explanation for rejection/change',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Event time',
  PRIMARY KEY (`id`),
  KEY `idx_booking_events_booking_id` (`booking_id`),
  KEY `idx_booking_events_actor_id` (`actor_id`),
  CONSTRAINT `fk_booking_events_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_events_actor_id` FOREIGN KEY (`actor_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_events_from_status` CHECK (from_status IS NULL OR from_status IN ('pending','confirmed','rejected','cancelled','completed')),
  CONSTRAINT `chk_booking_events_to_status` CHECK (to_status IN ('pending','confirmed','rejected','cancelled','completed')),
  CONSTRAINT `chk_booking_events_transition` CHECK ((from_status IS NULL AND to_status = 'pending') OR (from_status IS NOT NULL AND ((from_status = 'pending' AND to_status IN ('confirmed','rejected','cancelled')) OR (from_status = 'confirmed' AND to_status IN ('completed','cancelled')))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking: Cancellation reason and calculated refund entitlement.
-- Rules: CHECK refund_amount >= 0; transaction checks amount <= booking total and status = cancelled. This does not record an actual refund transfer.
CREATE TABLE `booking_cancellations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Cancellation ID',
  `booking_id` BIGINT UNSIGNED NOT NULL COMMENT 'One cancellation per booking',
  `cancelled_by` BIGINT UNSIGNED NOT NULL COMMENT 'Guest, Host or Admin actor',
  `reason` TEXT NOT NULL COMMENT 'Cancellation explanation',
  `refund_amount` DECIMAL(12,2) NOT NULL COMMENT 'Approved refund amount in booking currency',
  `calculation_details` JSON NOT NULL COMMENT 'Applied policy tier and calculation breakdown',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Cancellation time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_cancellations_booking_id` (`booking_id`),
  KEY `idx_booking_cancellations_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_booking_cancellations_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_cancellations_cancelled_by` FOREIGN KEY (`cancelled_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_cancellations_refund` CHECK (refund_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Community: One verified Guest review per completed booking and an optional Host reply.
-- Rules: CHECK rating BETWEEN 1 AND 5. Host reply and reply timestamp must both be present or both absent. Hide inappropriate content to retain history.
CREATE TABLE `reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Review ID',
  `booking_id` BIGINT UNSIGNED NOT NULL COMMENT 'Completed stay; author/listing derived from booking',
  `rating` SMALLINT NOT NULL COMMENT '1–5 stars',
  `comment` TEXT NOT NULL COMMENT 'Guest feedback',
  `host_reply` TEXT NULL COMMENT 'Listing Host response',
  `host_replied_at` DATETIME(6) NULL COMMENT 'Response time',
  `moderation_status` VARCHAR(10) NOT NULL COMMENT 'visible | hidden',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Review time',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'Latest edit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_booking_id` (`booking_id`),
  CONSTRAINT `fk_reviews_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_reviews_rating` CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT `chk_reviews_reply` CHECK ((host_reply IS NULL AND host_replied_at IS NULL) OR (host_reply IS NOT NULL AND host_replied_at IS NOT NULL)),
  CONSTRAINT `chk_reviews_moderation` CHECK (moderation_status IN ('visible','hidden'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Community: Save, unsave and list favorite accommodations.
-- Rules: Composite PK (user_id, listing_id). No separate named wishlist feature is assumed.
CREATE TABLE `wishlist_items` (
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Account saving a listing',
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Saved accommodation',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Saved time',
  PRIMARY KEY (`user_id`, `listing_id`),
  KEY `idx_wishlist_items_listing_id` (`listing_id`),
  CONSTRAINT `fk_wishlist_items_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_wishlist_items_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Community: Guest listing reports, fraud reports and booking complaints for Admin resolution.
-- Rules: CHECK at least one target is set; validate category/status sets. Closed reports require resolution and resolved_at.
CREATE TABLE `reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Report ID',
  `reporter_id` BIGINT UNSIGNED NOT NULL COMMENT 'Submitting account',
  `listing_id` BIGINT UNSIGNED NULL COMMENT 'Reported listing',
  `booking_id` BIGINT UNSIGNED NULL COMMENT 'Disputed booking',
  `category` VARCHAR(30) NOT NULL COMMENT 'misleading | fraud | complaint | other',
  `description` TEXT NOT NULL COMMENT 'Issue details',
  `status` VARCHAR(16) NOT NULL COMMENT 'open | investigating | resolved | dismissed',
  `assigned_admin_id` BIGINT UNSIGNED NULL COMMENT 'Admin handling the case',
  `resolution` TEXT NULL COMMENT 'Admin outcome',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Submission time',
  `resolved_at` DATETIME(6) NULL COMMENT 'Closure time',
  PRIMARY KEY (`id`),
  KEY `idx_reports_status_created_at` (`status`, `created_at`),
  KEY `idx_reports_reporter_id` (`reporter_id`),
  KEY `idx_reports_listing_id` (`listing_id`),
  KEY `idx_reports_booking_id` (`booking_id`),
  KEY `idx_reports_assigned_admin_id` (`assigned_admin_id`),
  CONSTRAINT `fk_reports_reporter_id` FOREIGN KEY (`reporter_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reports_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reports_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reports_assigned_admin_id` FOREIGN KEY (`assigned_admin_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_reports_target` CHECK (listing_id IS NOT NULL OR booking_id IS NOT NULL),
  CONSTRAINT `chk_reports_category` CHECK (category IN ('misleading','fraud','complaint','other')),
  CONSTRAINT `chk_reports_status` CHECK (status IN ('open','investigating','resolved','dismissed')),
  CONSTRAINT `chk_reports_closure` CHECK (status NOT IN ('resolved','dismissed') OR (resolution IS NOT NULL AND resolved_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operations: Booking event notifications and email delivery tracking.
-- Rules: UQ (user_id, booking_event_id, channel) prevents duplicate event notifications. Queue in the booking transaction; worker retries emails. Event must belong to booking_id.
CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Notification ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Recipient',
  `booking_id` BIGINT UNSIGNED NULL COMMENT 'Related booking',
  `booking_event_id` BIGINT UNSIGNED NULL COMMENT 'Triggering lifecycle event',
  `channel` VARCHAR(10) NOT NULL COMMENT 'in_app | email',
  `event_type` VARCHAR(40) NOT NULL COMMENT 'booking_requested / confirmed / rejected / cancelled',
  `title` VARCHAR(200) NOT NULL COMMENT 'Notification heading',
  `body` TEXT NOT NULL COMMENT 'Rendered notification content',
  `delivery_status` VARCHAR(10) NOT NULL COMMENT 'pending | sent | failed',
  `attempt_count` INTEGER NOT NULL COMMENT 'Delivery retries',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Queue time',
  `sent_at` DATETIME(6) NULL COMMENT 'Delivery time',
  `read_at` DATETIME(6) NULL COMMENT 'In-app read time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_user_id_booking_event_id_channel` (`user_id`, `booking_event_id`, `channel`),
  KEY `idx_notifications_user_id_read_at` (`user_id`, `read_at`),
  KEY `idx_notifications_booking_id` (`booking_id`),
  KEY `idx_notifications_booking_event_id` (`booking_event_id`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_notifications_booking_id` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_notifications_booking_event_id` FOREIGN KEY (`booking_event_id`)
    REFERENCES `booking_events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_notifications_channel` CHECK (channel IN ('in_app','email')),
  CONSTRAINT `chk_notifications_delivery` CHECK (delivery_status IN ('pending','sent','failed')),
  CONSTRAINT `chk_notifications_attempts` CHECK (attempt_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operations: Append-only record of user, listing, booking and moderation administration.
-- Rules: Polymorphic entity reference survives soft deletion; no FK to an arbitrary table. Restrict UPDATE/DELETE on audit rows.
CREATE TABLE `admin_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Audit entry ID',
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'Acting administrator',
  `action` VARCHAR(80) NOT NULL COMMENT 'Operation such as listing.approve',
  `entity_type` VARCHAR(40) NOT NULL COMMENT 'Affected table/entity type',
  `entity_id` BIGINT UNSIGNED NOT NULL COMMENT 'Affected identifier; deliberately not a foreign key',
  `change_summary` JSON NOT NULL COMMENT 'Redacted before/after values or action details',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Action time',
  PRIMARY KEY (`id`),
  KEY `idx_admin_audit_logs_admin_id` (`admin_id`),
  CONSTRAINT `fk_admin_audit_logs_admin_id` FOREIGN KEY (`admin_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operations: History of Admin approvals, rejections and listing removals.
-- Rules: Write event, current listing moderation fields and audit log atomically.
CREATE TABLE `listing_moderation_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Moderation event ID',
  `listing_id` BIGINT UNSIGNED NOT NULL COMMENT 'Moderated accommodation',
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reviewing administrator',
  `action` VARCHAR(12) NOT NULL COMMENT 'approve | reject | hide | remove',
  `reason` TEXT NULL COMMENT 'Required for reject/hide/remove',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Decision time',
  PRIMARY KEY (`id`),
  KEY `idx_listing_moderation_events_listing_id` (`listing_id`),
  KEY `idx_listing_moderation_events_admin_id` (`admin_id`),
  CONSTRAINT `fk_listing_moderation_events_listing_id` FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_listing_moderation_events_admin_id` FOREIGN KEY (`admin_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_listing_moderation_events_action` CHECK (action IN ('approve','reject','hide','remove')),
  CONSTRAINT `chk_listing_moderation_events_reason` CHECK (action = 'approve' OR reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operations: History of Admin account suspension, reactivation and soft deletion.
-- Rules: Both statuses must be active/suspended/deleted; write with user status change, session revocation and audit log.
CREATE TABLE `account_status_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Account status event ID',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Affected account',
  `admin_id` BIGINT UNSIGNED NOT NULL COMMENT 'Acting administrator',
  `from_status` VARCHAR(12) NOT NULL COMMENT 'Previous account status',
  `to_status` VARCHAR(12) NOT NULL COMMENT 'New account status',
  `reason` TEXT NOT NULL COMMENT 'Reason for account action',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Action time',
  PRIMARY KEY (`id`),
  KEY `idx_account_status_events_user_id` (`user_id`),
  KEY `idx_account_status_events_admin_id` (`admin_id`),
  CONSTRAINT `fk_account_status_events_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_account_status_events_admin_id` FOREIGN KEY (`admin_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_account_status_events_from_status` CHECK (from_status IN ('active','suspended','deleted')),
  CONSTRAINT `chk_account_status_events_to_status` CHECK (to_status IN ('active','suspended','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction/service rules (cross-row and cross-table conditions):
-- * Assign roles and enforce Guest/Host/Admin ownership in the service.
-- * Approvals, cancellations and calendar edits must lock the listing first:
--     START TRANSACTION;
--     SELECT id FROM listings WHERE id = ? FOR UPDATE;
--   Then use locking reads on the booking, availability and conflicting stays.
--   Conflicts are confirmed/completed stays where check_in < requested_check_out
--   AND check_out > requested_check_in, excluding the booking being approved.
--   Missing/closed availability is unavailable; pending stays reserve nothing.
--   Recheck active accounts, approved/visible/nondeleted listing, capacity,
--   Guest != Host, local stay dates and allowed transition; append the event
--   and queue notifications in the same transaction before COMMIT.
--   Reject conflicts and retry the entire transaction after a deadlock.
-- * A booking needs exactly one booking_nights row per date in [check_in, check_out).
--   Its total equals SUM(nightly_amount) + fee_amount. Quotes and policy snapshots
--   are immutable; snapshots include all policy fields, time zone and check-in time.
-- * Completion requires checkout to have passed in the listing time zone.
-- * Cancellation must atomically update status and record actor/reason/refund;
--   refund_amount <= booking total. This is entitlement, not payment settlement.
-- * Reviews require the actual Guest and a completed stay; replies require its Host.
-- * If a report names both a listing and booking, they must refer to the same listing.
-- * A notification's booking_event_id must belong to its booking_id.
-- * Material listing edits return to pending; moderation/account changes write
--   current state, history and redacted audit data atomically.
-- * Revoke sessions on logout, password changes and account suspension.
--   Consume unexpired reset tokens once using an atomic conditional update.
-- * Use soft deletion for users/listings. Restrict application UPDATE/DELETE
--   permissions for admin_audit_logs and booking_events to keep them append-only.
-- * Store password hashes and token hashes only; keep secrets/private contact
--   details out of audit JSON and notification payloads.
