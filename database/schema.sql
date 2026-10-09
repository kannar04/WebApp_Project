-- Canonical MariaDB 10.4 initialization aligned with the existing ERD database.
-- Structure only: no application rows, credentials or current AUTO_INCREMENT counters.
-- IF NOT EXISTS supports safe initialization reruns; it is NOT a schema migration.
CREATE DATABASE IF NOT EXISTS db_home2home CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_home2home;

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Generated account ID',
  `email` varchar(254) NOT NULL COMMENT 'Case-insensitive login email',
  `password_hash` varchar(255) NOT NULL COMMENT 'Argon2id/bcrypt hash',
  `full_name` varchar(150) NOT NULL COMMENT 'Display name',
  `phone` varchar(25) DEFAULT NULL COMMENT 'Contact number',
  `avatar_url` text DEFAULT NULL COMMENT 'Profile image',
  `status` varchar(12) NOT NULL COMMENT 'active | suspended | deleted',
  `created_at` datetime(6) NOT NULL COMMENT 'Registration time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last profile update',
  `deleted_at` datetime(6) DEFAULT NULL COMMENT 'Soft deletion time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  CONSTRAINT `chk_users_status` CHECK (`status` in ('active','suspended','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Account receiving the role',
  `role` varchar(10) NOT NULL COMMENT 'guest | host | admin',
  `granted_at` datetime(6) NOT NULL COMMENT 'Role assignment time',
  PRIMARY KEY (`user_id`,`role`),
  CONSTRAINT `fk_user_roles_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_user_roles_role` CHECK (`role` in ('guest','host','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `property_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Type ID',
  `name` varchar(80) NOT NULL COMMENT 'Type label',
  `description` text DEFAULT NULL COMMENT 'Category explanation',
  `is_active` tinyint(1) NOT NULL COMMENT 'Whether available for new listings',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_types_name` (`name`),
  CONSTRAINT `chk_property_types_is_active` CHECK (`is_active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cancellation_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Policy ID',
  `name` varchar(80) NOT NULL COMMENT 'Policy label',
  `description` text NOT NULL COMMENT 'Guest-facing terms',
  `cutoff_hours` int(11) NOT NULL COMMENT 'Hours before check-in separating refund tiers',
  `early_refund_pct` decimal(5,2) NOT NULL COMMENT 'Night-subtotal percentage before cutoff',
  `late_refund_pct` decimal(5,2) NOT NULL COMMENT 'Night-subtotal percentage after cutoff',
  `refund_fees` tinyint(1) NOT NULL COMMENT 'Whether the fixed fee is refundable',
  `is_active` tinyint(1) NOT NULL COMMENT 'Available for new listings',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cancellation_policies_name` (`name`),
  CONSTRAINT `chk_cancellation_policies_cutoff` CHECK (`cutoff_hours` >= 0),
  CONSTRAINT `chk_cancellation_policies_early_refund` CHECK (`early_refund_pct` between 0 and 100),
  CONSTRAINT `chk_cancellation_policies_late_refund` CHECK (`late_refund_pct` between 0 and 100),
  CONSTRAINT `chk_cancellation_policies_refund_fees` CHECK (`refund_fees` in (0,1)),
  CONSTRAINT `chk_cancellation_policies_is_active` CHECK (`is_active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Listing ID',
  `host_id` bigint(20) unsigned NOT NULL COMMENT 'Owner with Host role',
  `property_type_id` bigint(20) unsigned NOT NULL COMMENT 'Accommodation type',
  `cancellation_policy_id` bigint(20) unsigned NOT NULL COMMENT 'Current cancellation terms',
  `title` varchar(200) NOT NULL COMMENT 'Listing title',
  `description` text NOT NULL COMMENT 'Accommodation description',
  `address` text NOT NULL COMMENT 'Street address',
  `city` varchar(100) NOT NULL COMMENT 'Searchable city',
  `country_code` char(2) NOT NULL COMMENT 'ISO country code',
  `latitude` decimal(9,6) DEFAULT NULL COMMENT 'Map latitude',
  `longitude` decimal(9,6) DEFAULT NULL COMMENT 'Map longitude',
  `time_zone` text NOT NULL COMMENT 'IANA zone, e.g. Asia/Ho_Chi_Minh',
  `base_nightly_rate` decimal(12,2) NOT NULL COMMENT 'Current price per night',
  `fee_amount` decimal(12,2) NOT NULL COMMENT 'Fixed surcharge per stay',
  `currency` char(3) NOT NULL COMMENT 'ISO currency, e.g. VND',
  `max_guests` int(11) NOT NULL COMMENT 'Guest capacity',
  `room_count` int(11) NOT NULL COMMENT 'Total rooms',
  `bedroom_count` int(11) NOT NULL COMMENT 'Number of bedrooms',
  `bed_count` int(11) NOT NULL COMMENT 'Number of beds',
  `check_in_time` time NOT NULL COMMENT 'Local check-in time',
  `check_out_time` time NOT NULL COMMENT 'Local check-out time',
  `smoking_allowed` tinyint(1) NOT NULL COMMENT 'Smoking rule',
  `pets_allowed` tinyint(1) NOT NULL COMMENT 'Pet rule',
  `parties_allowed` tinyint(1) NOT NULL COMMENT 'Party rule',
  `house_rules` text DEFAULT NULL COMMENT 'Additional rules',
  `moderation_status` varchar(12) NOT NULL COMMENT 'draft | pending | approved | rejected',
  `moderation_reason` text DEFAULT NULL COMMENT 'Reason for rejection or removal',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin moderator',
  `reviewed_at` datetime(6) DEFAULT NULL COMMENT 'Moderation time',
  `is_visible` tinyint(1) NOT NULL COMMENT 'Host/Admin visibility switch',
  `created_at` datetime(6) NOT NULL COMMENT 'Creation time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last edit',
  `deleted_at` datetime(6) DEFAULT NULL COMMENT 'Soft deletion time',
  PRIMARY KEY (`id`),
  KEY `idx_listings_moderation_status_is_visible_city_base_nightly_rate` (`moderation_status`,`is_visible`,`city`,`base_nightly_rate`),
  KEY `idx_listings_host_id` (`host_id`),
  KEY `idx_listings_property_type_id` (`property_type_id`),
  KEY `idx_listings_cancellation_policy_id` (`cancellation_policy_id`),
  KEY `idx_listings_reviewed_by` (`reviewed_by`),
  FULLTEXT KEY `ft_listings_title_description` (`title`,`description`),
  CONSTRAINT `fk_listings_cancellation_policy_id` FOREIGN KEY (`cancellation_policy_id`) REFERENCES `cancellation_policies` (`id`),
  CONSTRAINT `fk_listings_host_id` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_listings_property_type_id` FOREIGN KEY (`property_type_id`) REFERENCES `property_types` (`id`),
  CONSTRAINT `fk_listings_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_listings_prices` CHECK (`base_nightly_rate` >= 0 and `fee_amount` >= 0),
  CONSTRAINT `chk_listings_capacity` CHECK (`max_guests` > 0),
  CONSTRAINT `chk_listings_counts` CHECK (`room_count` >= 0 and `bedroom_count` >= 0 and `bed_count` >= 0),
  CONSTRAINT `chk_listings_coordinates` CHECK (`latitude` is null and `longitude` is null or `latitude` is not null and `longitude` is not null and `latitude` between -90 and 90 and `longitude` between -180 and 180),
  CONSTRAINT `chk_listings_moderation` CHECK (`moderation_status` in ('draft','pending','approved','rejected')),
  CONSTRAINT `chk_listings_smoking_allowed` CHECK (`smoking_allowed` in (0,1)),
  CONSTRAINT `chk_listings_pets_allowed` CHECK (`pets_allowed` in (0,1)),
  CONSTRAINT `chk_listings_parties_allowed` CHECK (`parties_allowed` in (0,1)),
  CONSTRAINT `chk_listings_is_visible` CHECK (`is_visible` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listing_photos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Photo ID',
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Photographed listing',
  `image_url` text NOT NULL COMMENT 'Object storage URL/key',
  `alt_text` text DEFAULT NULL COMMENT 'Accessible image description',
  `sort_order` int(11) NOT NULL COMMENT 'Display position',
  `created_at` datetime(6) NOT NULL COMMENT 'Upload time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_listing_photos_listing_id_sort_order` (`listing_id`,`sort_order`),
  CONSTRAINT `fk_listing_photos_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_listing_photos_sort_order` CHECK (`sort_order` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `amenities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Amenity ID',
  `name` varchar(80) NOT NULL COMMENT 'Amenity label',
  `icon_key` varchar(80) DEFAULT NULL COMMENT 'UI icon identifier',
  `is_active` tinyint(1) NOT NULL COMMENT 'Available for selection',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_amenities_name` (`name`),
  CONSTRAINT `chk_amenities_is_active` CHECK (`is_active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listing_amenities` (
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Accommodation',
  `amenity_id` bigint(20) unsigned NOT NULL COMMENT 'Provided amenity',
  PRIMARY KEY (`listing_id`,`amenity_id`),
  KEY `idx_listing_amenities_amenity_id` (`amenity_id`),
  CONSTRAINT `fk_listing_amenities_amenity_id` FOREIGN KEY (`amenity_id`) REFERENCES `amenities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_listing_amenities_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listing_availability` (
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Rentable unit',
  `stay_date` date NOT NULL COMMENT 'Night in the listing local time zone',
  `is_open` tinyint(1) NOT NULL COMMENT 'Host offers this night',
  `block_reason` text DEFAULT NULL COMMENT 'Private note for a closed date',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last calendar edit',
  PRIMARY KEY (`listing_id`,`stay_date`),
  CONSTRAINT `fk_listing_availability_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_listing_availability_is_open` CHECK (`is_open` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Booking ID',
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Requested accommodation',
  `guest_id` bigint(20) unsigned NOT NULL COMMENT 'Booking Guest',
  `check_in` date NOT NULL COMMENT 'Arrival date',
  `check_out` date NOT NULL COMMENT 'Departure date (exclusive)',
  `guest_count` int(11) NOT NULL COMMENT 'Number of guests',
  `status` varchar(12) NOT NULL COMMENT 'pending | confirmed | rejected | cancelled | completed',
  `currency` char(3) NOT NULL COMMENT 'Currency at booking time',
  `fee_amount` decimal(12,2) NOT NULL COMMENT 'Per-stay surcharge snapshot',
  `total_amount` decimal(12,2) NOT NULL COMMENT 'Sum of night prices plus surcharge',
  `policy_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Immutable full cancellation terms and local check-in context' CHECK (json_valid(`policy_snapshot`)),
  `created_at` datetime(6) NOT NULL COMMENT 'Request time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last status update',
  PRIMARY KEY (`id`),
  KEY `idx_bookings_guest_id_created_at` (`guest_id`,`created_at`),
  KEY `idx_bookings_listing_id_status_check_in` (`listing_id`,`status`,`check_in`),
  CONSTRAINT `fk_bookings_guest_id` FOREIGN KEY (`guest_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_bookings_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`),
  CONSTRAINT `chk_bookings_dates` CHECK (`check_out` > `check_in`),
  CONSTRAINT `chk_bookings_guest_count` CHECK (`guest_count` > 0),
  CONSTRAINT `chk_bookings_amounts` CHECK (`fee_amount` >= 0 and `total_amount` >= 0),
  CONSTRAINT `chk_bookings_status` CHECK (`status` in ('pending','confirmed','rejected','cancelled','completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_nights` (
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Quoted booking',
  `stay_date` date NOT NULL COMMENT 'Local night within the stay interval',
  `nightly_amount` decimal(12,2) NOT NULL COMMENT 'Night price at request time',
  PRIMARY KEY (`booking_id`,`stay_date`),
  CONSTRAINT `fk_booking_nights_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `chk_booking_nights_amount` CHECK (`nightly_amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Event ID',
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Booking affected',
  `actor_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Actor; NULL for automated system',
  `from_status` varchar(12) DEFAULT NULL COMMENT 'NULL only for initial request',
  `to_status` varchar(12) NOT NULL COMMENT 'New status',
  `reason` text DEFAULT NULL COMMENT 'Explanation for rejection/change',
  `created_at` datetime(6) NOT NULL COMMENT 'Event time',
  PRIMARY KEY (`id`),
  KEY `idx_booking_events_booking_id` (`booking_id`),
  KEY `idx_booking_events_actor_id` (`actor_id`),
  CONSTRAINT `fk_booking_events_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_booking_events_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `chk_booking_events_from_status` CHECK (`from_status` is null or `from_status` in ('pending','confirmed','rejected','cancelled','completed')),
  CONSTRAINT `chk_booking_events_to_status` CHECK (`to_status` in ('pending','confirmed','rejected','cancelled','completed')),
  CONSTRAINT `chk_booking_events_transition` CHECK (`from_status` is null and `to_status` = 'pending' or `from_status` is not null and (`from_status` = 'pending' and `to_status` in ('confirmed','rejected','cancelled') or `from_status` = 'confirmed' and `to_status` in ('completed','cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_cancellations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Cancellation ID',
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'One cancellation per booking',
  `cancelled_by` bigint(20) unsigned NOT NULL COMMENT 'Guest, Host or Admin actor',
  `reason` text NOT NULL COMMENT 'Cancellation explanation',
  `refund_amount` decimal(12,2) NOT NULL COMMENT 'Approved refund amount in booking currency',
  `calculation_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Applied policy tier and calculation breakdown' CHECK (json_valid(`calculation_details`)),
  `created_at` datetime(6) NOT NULL COMMENT 'Cancellation time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_cancellations_booking_id` (`booking_id`),
  KEY `idx_booking_cancellations_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_booking_cancellations_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `fk_booking_cancellations_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_booking_cancellations_refund` CHECK (`refund_amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Review ID',
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Completed stay; author/listing derived from booking',
  `rating` smallint(6) NOT NULL COMMENT '1–5 stars',
  `comment` text NOT NULL COMMENT 'Guest feedback',
  `host_reply` text DEFAULT NULL COMMENT 'Listing Host response',
  `host_replied_at` datetime(6) DEFAULT NULL COMMENT 'Response time',
  `moderation_status` varchar(10) NOT NULL COMMENT 'visible | hidden',
  `created_at` datetime(6) NOT NULL COMMENT 'Review time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Latest edit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_booking_id` (`booking_id`),
  CONSTRAINT `fk_reviews_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `chk_reviews_rating` CHECK (`rating` between 1 and 5),
  CONSTRAINT `chk_reviews_reply` CHECK (`host_reply` is null and `host_replied_at` is null or `host_reply` is not null and `host_replied_at` is not null),
  CONSTRAINT `chk_reviews_moderation` CHECK (`moderation_status` in ('visible','hidden'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wishlist_items` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Account saving a listing',
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Saved accommodation',
  `created_at` datetime(6) NOT NULL COMMENT 'Saved time',
  PRIMARY KEY (`user_id`,`listing_id`),
  KEY `idx_wishlist_items_listing_id` (`listing_id`),
  CONSTRAINT `fk_wishlist_items_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_items_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Report ID',
  `reporter_id` bigint(20) unsigned NOT NULL COMMENT 'Submitting account',
  `listing_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Reported listing',
  `booking_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Disputed booking',
  `category` varchar(30) NOT NULL COMMENT 'misleading | fraud | complaint | other',
  `description` text NOT NULL COMMENT 'Issue details',
  `status` varchar(16) NOT NULL COMMENT 'open | investigating | resolved | dismissed',
  `assigned_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin handling the case',
  `resolution` text DEFAULT NULL COMMENT 'Admin outcome',
  `created_at` datetime(6) NOT NULL COMMENT 'Submission time',
  `resolved_at` datetime(6) DEFAULT NULL COMMENT 'Closure time',
  PRIMARY KEY (`id`),
  KEY `idx_reports_status_created_at` (`status`,`created_at`),
  KEY `idx_reports_reporter_id` (`reporter_id`),
  KEY `idx_reports_listing_id` (`listing_id`),
  KEY `idx_reports_booking_id` (`booking_id`),
  KEY `idx_reports_assigned_admin_id` (`assigned_admin_id`),
  CONSTRAINT `fk_reports_assigned_admin_id` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reports_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `fk_reports_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`),
  CONSTRAINT `fk_reports_reporter_id` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_reports_target` CHECK (`listing_id` is not null or `booking_id` is not null),
  CONSTRAINT `chk_reports_category` CHECK (`category` in ('misleading','fraud','complaint','other')),
  CONSTRAINT `chk_reports_status` CHECK (`status` in ('open','investigating','resolved','dismissed')),
  CONSTRAINT `chk_reports_closure` CHECK (`status` not in ('resolved','dismissed') or `resolution` is not null and `resolved_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Notification ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Recipient',
  `booking_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Related booking',
  `booking_event_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Triggering lifecycle event',
  `channel` varchar(10) NOT NULL COMMENT 'in_app | email',
  `event_type` varchar(40) NOT NULL COMMENT 'booking_requested / confirmed / rejected / cancelled',
  `title` varchar(200) NOT NULL COMMENT 'Notification heading',
  `body` text NOT NULL COMMENT 'Rendered notification content',
  `delivery_status` varchar(10) NOT NULL COMMENT 'pending | sent | failed',
  `attempt_count` int(11) NOT NULL COMMENT 'Delivery retries',
  `created_at` datetime(6) NOT NULL COMMENT 'Queue time',
  `sent_at` datetime(6) DEFAULT NULL COMMENT 'Delivery time',
  `read_at` datetime(6) DEFAULT NULL COMMENT 'In-app read time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_user_id_booking_event_id_channel` (`user_id`,`booking_event_id`,`channel`),
  KEY `idx_notifications_user_id_read_at` (`user_id`,`read_at`),
  KEY `idx_notifications_booking_id` (`booking_id`),
  KEY `idx_notifications_booking_event_id` (`booking_event_id`),
  CONSTRAINT `fk_notifications_booking_event_id` FOREIGN KEY (`booking_event_id`) REFERENCES `booking_events` (`id`),
  CONSTRAINT `fk_notifications_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_notifications_channel` CHECK (`channel` in ('in_app','email')),
  CONSTRAINT `chk_notifications_delivery` CHECK (`delivery_status` in ('pending','sent','failed')),
  CONSTRAINT `chk_notifications_attempts` CHECK (`attempt_count` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listing_moderation_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Moderation event ID',
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Moderated accommodation',
  `admin_id` bigint(20) unsigned NOT NULL COMMENT 'Reviewing administrator',
  `action` varchar(12) NOT NULL COMMENT 'approve | reject | hide | remove',
  `reason` text DEFAULT NULL COMMENT 'Required for reject/hide/remove',
  `created_at` datetime(6) NOT NULL COMMENT 'Decision time',
  PRIMARY KEY (`id`),
  KEY `idx_listing_moderation_events_listing_id` (`listing_id`),
  KEY `idx_listing_moderation_events_admin_id` (`admin_id`),
  CONSTRAINT `fk_listing_moderation_events_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_listing_moderation_events_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`),
  CONSTRAINT `chk_listing_moderation_events_action` CHECK (`action` in ('approve','reject','hide','remove')),
  CONSTRAINT `chk_listing_moderation_events_reason` CHECK (`action` = 'approve' or `reason` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Audit entry ID',
  `admin_id` bigint(20) unsigned NOT NULL COMMENT 'Acting administrator',
  `action` varchar(80) NOT NULL COMMENT 'Operation such as listing.approve',
  `entity_type` varchar(40) NOT NULL COMMENT 'Affected table/entity type',
  `entity_id` bigint(20) unsigned NOT NULL COMMENT 'Affected identifier; deliberately not a foreign key',
  `change_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Redacted before/after values or action details' CHECK (json_valid(`change_summary`)),
  `created_at` datetime(6) NOT NULL COMMENT 'Action time',
  PRIMARY KEY (`id`),
  KEY `idx_admin_audit_logs_admin_id` (`admin_id`),
  CONSTRAINT `fk_admin_audit_logs_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Session identifier',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Authenticated account',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Hash of the session secret',
  `created_at` datetime(6) NOT NULL COMMENT 'Issued time',
  `expires_at` datetime(6) NOT NULL COMMENT 'Expiry time',
  `revoked_at` datetime(6) DEFAULT NULL COMMENT 'Logout or forced revocation',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token_hash` (`token_hash`),
  KEY `idx_sessions_user_id` (`user_id`),
  CONSTRAINT `fk_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_sessions_expiry` CHECK (`expires_at` > `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Reset request ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Account to recover',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Hashed recovery token',
  `created_at` datetime(6) NOT NULL COMMENT 'Issue time',
  `expires_at` datetime(6) NOT NULL COMMENT 'Expiry time',
  `used_at` datetime(6) DEFAULT NULL COMMENT 'Consumption time',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_tokens_token_hash` (`token_hash`),
  KEY `idx_password_reset_tokens_user_id` (`user_id`),
  CONSTRAINT `fk_password_reset_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_password_reset_tokens_expiry` CHECK (`expires_at` > `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_status_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Account status event ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Affected account',
  `admin_id` bigint(20) unsigned NOT NULL COMMENT 'Acting administrator',
  `from_status` varchar(12) NOT NULL COMMENT 'Previous account status',
  `to_status` varchar(12) NOT NULL COMMENT 'New account status',
  `reason` text NOT NULL COMMENT 'Reason for account action',
  `created_at` datetime(6) NOT NULL COMMENT 'Action time',
  PRIMARY KEY (`id`),
  KEY `idx_account_status_events_user_id` (`user_id`),
  KEY `idx_account_status_events_admin_id` (`admin_id`),
  CONSTRAINT `fk_account_status_events_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_account_status_events_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_account_status_events_from_status` CHECK (`from_status` in ('active','suspended','deleted')),
  CONSTRAINT `chk_account_status_events_to_status` CHECK (`to_status` in ('active','suspended','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

