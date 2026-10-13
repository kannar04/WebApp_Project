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

-- Nice-to-have booking extras: flexible pricing, promo codes, Instant Book, payment status
CREATE TABLE IF NOT EXISTS `listing_booking_settings` (
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Listing owning these optional booking rules (1:1)',
  `weekend_nightly_rate` decimal(12,2) DEFAULT NULL COMMENT 'Friday/Saturday night price; NULL keeps the base rate',
  `holiday_nightly_rate` decimal(12,2) DEFAULT NULL COMMENT 'Public-holiday night price; NULL falls back to weekend/base rate',
  `weekly_discount_pct` decimal(5,2) DEFAULT NULL COMMENT 'Nights-subtotal discount for stays of 7+ nights',
  `monthly_discount_pct` decimal(5,2) DEFAULT NULL COMMENT 'Discount for 28+ nights; replaces weekly, never stacked',
  `instant_book` tinyint(1) NOT NULL COMMENT 'Valid requests are confirmed automatically',
  `created_at` datetime(6) NOT NULL COMMENT 'First save time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last Host change',
  PRIMARY KEY (`listing_id`),
  CONSTRAINT `fk_listing_booking_settings_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_listing_booking_settings_rates` CHECK ((`weekend_nightly_rate` is null or `weekend_nightly_rate` >= 0) and (`holiday_nightly_rate` is null or `holiday_nightly_rate` >= 0)),
  CONSTRAINT `chk_listing_booking_settings_discounts` CHECK ((`weekly_discount_pct` is null or `weekly_discount_pct` between 0 and 90) and (`monthly_discount_pct` is null or `monthly_discount_pct` between 0 and 90)),
  CONSTRAINT `chk_listing_booking_settings_instant_book` CHECK (`instant_book` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_holidays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Holiday ID',
  `holiday_date` date NOT NULL COMMENT 'Night priced with the holiday rate',
  `name` varchar(120) NOT NULL COMMENT 'Holiday name shown to Admin and Guests',
  `is_demo` tinyint(1) NOT NULL COMMENT 'Seeded demo date that an Admin has not confirmed yet',
  `created_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin who added or confirmed it; NULL for seed data',
  `created_at` datetime(6) NOT NULL COMMENT 'Creation time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last Admin change',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_public_holidays_holiday_date` (`holiday_date`),
  KEY `idx_public_holidays_created_by` (`created_by`),
  CONSTRAINT `fk_public_holidays_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_public_holidays_is_demo` CHECK (`is_demo` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promotions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Promotion ID',
  `code` varchar(20) NOT NULL COMMENT 'Uppercase A-Z/0-9 code, 4-20 characters',
  `description` varchar(255) NOT NULL COMMENT 'Guest-facing description',
  `discount_type` varchar(10) NOT NULL COMMENT 'percent | fixed',
  `discount_value` decimal(12,2) NOT NULL COMMENT 'Percent (0-100] or fixed amount in VND',
  `max_discount_amount` decimal(12,2) DEFAULT NULL COMMENT 'Cap for percent codes; NULL means no cap',
  `min_nights` smallint(5) unsigned DEFAULT NULL COMMENT 'Minimum nights; NULL means none',
  `min_subtotal` decimal(12,2) DEFAULT NULL COMMENT 'Minimum nights subtotal after stay-length discount',
  `valid_from` date NOT NULL COMMENT 'First booking date the code is accepted (Vietnam time)',
  `valid_to` date NOT NULL COMMENT 'Last booking date the code is accepted (inclusive)',
  `usage_limit` int(10) unsigned DEFAULT NULL COMMENT 'Maximum pending/confirmed/completed bookings; NULL means unlimited',
  `is_active` tinyint(1) NOT NULL COMMENT 'Admin on/off switch; used codes are deactivated, not deleted',
  `created_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Creating Admin; NULL for seed data',
  `created_at` datetime(6) NOT NULL COMMENT 'Creation time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last Admin change',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promotions_code` (`code`),
  KEY `idx_promotions_created_by` (`created_by`),
  CONSTRAINT `fk_promotions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_promotions_code` CHECK (cast(`code` as binary) regexp '^[A-Z0-9]{4,20}$'),
  CONSTRAINT `chk_promotions_discount_type` CHECK (`discount_type` in ('percent','fixed')),
  CONSTRAINT `chk_promotions_discount_value` CHECK (`discount_value` > 0 and (`discount_type` = 'fixed' or `discount_value` <= 100)),
  CONSTRAINT `chk_promotions_conditions` CHECK ((`max_discount_amount` is null or `max_discount_amount` > 0) and (`min_nights` is null or `min_nights` >= 1) and (`min_subtotal` is null or `min_subtotal` >= 0) and (`usage_limit` is null or `usage_limit` >= 1)),
  CONSTRAINT `chk_promotions_dates` CHECK (`valid_to` >= `valid_from`),
  CONSTRAINT `chk_promotions_is_active` CHECK (`is_active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_price_breakdowns` (
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Priced booking (1:1)',
  `nights_subtotal` decimal(12,2) NOT NULL COMMENT 'Sum of booking_nights.nightly_amount',
  `length_discount_type` varchar(10) NOT NULL COMMENT 'none | weekly | monthly',
  `length_discount_pct` decimal(5,2) NOT NULL COMMENT 'Applied stay-length percent',
  `length_discount_amount` decimal(12,2) NOT NULL COMMENT 'Stay-length discount amount',
  `promotion_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Applied promo; one use while the booking is pending/confirmed/completed',
  `promotion_code` varchar(20) DEFAULT NULL COMMENT 'Promo code snapshot',
  `promotion_discount_amount` decimal(12,2) NOT NULL COMMENT 'Promo discount amount',
  `fee_amount` decimal(12,2) NOT NULL COMMENT 'Undiscounted per-stay fee snapshot',
  `total_amount` decimal(12,2) NOT NULL COMMENT 'Equals bookings.total_amount at creation',
  `is_instant_book` tinyint(1) NOT NULL COMMENT 'Confirmed automatically by Instant Book',
  `created_at` datetime(6) NOT NULL COMMENT 'Snapshot time',
  PRIMARY KEY (`booking_id`),
  KEY `idx_booking_price_breakdowns_promotion_id` (`promotion_id`),
  CONSTRAINT `fk_booking_price_breakdowns_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_price_breakdowns_promotion_id` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`),
  CONSTRAINT `chk_booking_price_breakdowns_length` CHECK (`length_discount_type` in ('none','weekly','monthly')),
  CONSTRAINT `chk_booking_price_breakdowns_amounts` CHECK (`nights_subtotal` >= 0 and `length_discount_amount` >= 0 and `promotion_discount_amount` >= 0 and `fee_amount` >= 0 and `total_amount` >= 0),
  CONSTRAINT `chk_booking_price_breakdowns_promotion` CHECK (`promotion_id` is null and `promotion_code` is null and `promotion_discount_amount` = 0 or `promotion_id` is not null and `promotion_code` is not null),
  CONSTRAINT `chk_booking_price_breakdowns_instant` CHECK (`is_instant_book` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_payments` (
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Booking being paid (1:1)',
  `method` varchar(20) NOT NULL COMMENT 'pay_at_checkin; online gateways are not integrated',
  `amount_due` decimal(12,2) NOT NULL COMMENT 'Booking total at creation',
  `status` varchar(10) NOT NULL COMMENT 'unpaid | paid | refunded',
  `paid_at` datetime(6) DEFAULT NULL COMMENT 'Collection time',
  `paid_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Host or Admin who recorded the collection',
  `refunded_at` datetime(6) DEFAULT NULL COMMENT 'Refund record time',
  `refunded_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin who recorded the refund',
  `note` varchar(500) DEFAULT NULL COMMENT 'Latest Host/Admin payment note',
  `created_at` datetime(6) NOT NULL COMMENT 'Record creation time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last status change',
  PRIMARY KEY (`booking_id`),
  KEY `idx_booking_payments_status` (`status`),
  KEY `idx_booking_payments_paid_by` (`paid_by`),
  KEY `idx_booking_payments_refunded_by` (`refunded_by`),
  CONSTRAINT `fk_booking_payments_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_payments_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_booking_payments_refunded_by` FOREIGN KEY (`refunded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_booking_payments_method` CHECK (`method` in ('pay_at_checkin')),
  CONSTRAINT `chk_booking_payments_status` CHECK (`status` in ('unpaid','paid','refunded')),
  CONSTRAINT `chk_booking_payments_amount` CHECK (`amount_due` >= 0),
  CONSTRAINT `chk_booking_payments_state` CHECK (`status` = 'unpaid' and `paid_at` is null and `refunded_at` is null or `status` = 'paid' and `paid_at` is not null and `refunded_at` is null or `status` = 'refunded' and `paid_at` is not null and `refunded_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_payment_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Payment event ID',
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Booking whose payment changed',
  `actor_id` bigint(20) unsigned NOT NULL COMMENT 'Host or Admin performing the change',
  `from_status` varchar(10) NOT NULL COMMENT 'Previous payment status',
  `to_status` varchar(10) NOT NULL COMMENT 'New payment status',
  `note` varchar(500) DEFAULT NULL COMMENT 'Reason or receipt note',
  `created_at` datetime(6) NOT NULL COMMENT 'Change time',
  PRIMARY KEY (`id`),
  KEY `idx_booking_payment_events_booking_id` (`booking_id`),
  KEY `idx_booking_payment_events_actor_id` (`actor_id`),
  CONSTRAINT `fk_booking_payment_events_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_payment_events_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_booking_payment_events_transition` CHECK (`from_status` = 'unpaid' and `to_status` = 'paid' or `from_status` = 'paid' and `to_status` = 'refunded')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nice-to-have trust: identity verification, trusted Host badge, check-in guide
CREATE TABLE IF NOT EXISTS `identity_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Verification request ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Account asking to be verified',
  `full_name_on_card` varchar(150) NOT NULL COMMENT 'Full name exactly as printed on the CCCD',
  `phone` varchar(10) NOT NULL COMMENT 'Normalized Vietnamese mobile number (10 digits)',
  `id_number` varchar(12) NOT NULL COMMENT 'Full 12-digit CCCD only while pending; masked ********NNNN after the decision',
  `status` varchar(10) NOT NULL COMMENT 'pending | approved | rejected',
  `decision_reason` text DEFAULT NULL COMMENT 'Admin note; required when rejected',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Deciding administrator',
  `reviewed_at` datetime(6) DEFAULT NULL COMMENT 'Decision time',
  `created_at` datetime(6) NOT NULL COMMENT 'Submission time',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last status change',
  PRIMARY KEY (`id`),
  KEY `idx_identity_verifications_user_id_status` (`user_id`,`status`),
  KEY `idx_identity_verifications_status_created_at` (`status`,`created_at`),
  KEY `idx_identity_verifications_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_identity_verifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_identity_verifications_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_identity_verifications_status` CHECK (`status` in ('pending','approved','rejected')),
  CONSTRAINT `chk_identity_verifications_phone` CHECK (`phone` regexp '^0[35789][0-9]{8}$'),
  CONSTRAINT `chk_identity_verifications_id_number` CHECK (`status` = 'pending' and `id_number` regexp '^[0-9]{12}$' or `status` <> 'pending' and `id_number` regexp '^[*]{8}[0-9]{4}$'),
  CONSTRAINT `chk_identity_verifications_decision` CHECK (`status` = 'pending' and `reviewed_by` is null and `reviewed_at` is null or `status` <> 'pending' and `reviewed_by` is not null and `reviewed_at` is not null),
  CONSTRAINT `chk_identity_verifications_reason` CHECK (`status` <> 'rejected' or `decision_reason` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listing_checkin_guides` (
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Accommodation owning the guide (1:1)',
  `arrival_instructions` text NOT NULL COMMENT 'How to reach and enter the property',
  `door_code` varchar(60) DEFAULT NULL COMMENT 'Door or lockbox code; private to entitled booking participants',
  `wifi_name` varchar(100) DEFAULT NULL COMMENT 'Wi-Fi network name',
  `wifi_password` varchar(100) DEFAULT NULL COMMENT 'Wi-Fi password; private to entitled booking participants',
  `note` text DEFAULT NULL COMMENT 'Optional extra Host note',
  `updated_at` datetime(6) NOT NULL COMMENT 'Last Host edit',
  PRIMARY KEY (`listing_id`),
  CONSTRAINT `fk_listing_checkin_guides_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_listing_checkin_guides_arrival` CHECK (char_length(`arrival_instructions`) between 10 and 3000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nice-to-have conversation: booking messages, check-in reminders
CREATE TABLE IF NOT EXISTS `booking_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Message ID',
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Booking whose Guest and Host converse',
  `sender_id` bigint(20) unsigned NOT NULL COMMENT 'Participant who wrote the message',
  `recipient_id` bigint(20) unsigned NOT NULL COMMENT 'Other booking participant',
  `body` text NOT NULL COMMENT 'Plain-text content, 1-2000 characters',
  `created_at` datetime(6) NOT NULL COMMENT 'Send time',
  `read_at` datetime(6) DEFAULT NULL COMMENT 'Time the recipient opened the thread',
  `hidden_at` datetime(6) DEFAULT NULL COMMENT 'Admin moderation hide time',
  `hidden_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Administrator who hid the message',
  `hidden_reason` varchar(500) DEFAULT NULL COMMENT 'Moderation reason while hidden',
  PRIMARY KEY (`id`),
  KEY `idx_booking_messages_booking_id_created_at` (`booking_id`,`created_at`),
  KEY `idx_booking_messages_recipient_id_read_at` (`recipient_id`,`read_at`),
  KEY `idx_booking_messages_sender_id` (`sender_id`),
  KEY `idx_booking_messages_hidden_by` (`hidden_by`),
  CONSTRAINT `fk_booking_messages_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_messages_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_booking_messages_recipient_id` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_booking_messages_hidden_by` FOREIGN KEY (`hidden_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_booking_messages_body` CHECK (char_length(`body`) between 1 and 2000),
  CONSTRAINT `chk_booking_messages_participants` CHECK (`sender_id` <> `recipient_id`),
  CONSTRAINT `chk_booking_messages_hidden` CHECK (`hidden_at` is null and `hidden_by` is null and `hidden_reason` is null or `hidden_at` is not null and `hidden_by` is not null and `hidden_reason` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_reminders` (
  `booking_id` bigint(20) unsigned NOT NULL COMMENT 'Confirmed booking being reminded',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Reminded participant',
  `kind` varchar(20) NOT NULL COMMENT 'guest_check_in | host_check_in',
  `notification_id` bigint(20) unsigned DEFAULT NULL COMMENT 'In-app notification created for this reminder',
  `created_at` datetime(6) NOT NULL COMMENT 'Reminder creation time',
  PRIMARY KEY (`booking_id`,`user_id`,`kind`),
  KEY `idx_booking_reminders_user_id` (`user_id`),
  KEY `idx_booking_reminders_notification_id` (`notification_id`),
  CONSTRAINT `fk_booking_reminders_booking_id` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_reminders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_booking_reminders_notification_id` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_booking_reminders_kind` CHECK (`kind` in ('guest_check_in','host_check_in'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nice-to-have discovery: map search, recommendations
CREATE TABLE IF NOT EXISTS `listing_views` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Signed-in account that opened the listing',
  `listing_id` bigint(20) unsigned NOT NULL COMMENT 'Viewed accommodation',
  `view_count` int(11) NOT NULL COMMENT 'Number of detail-page visits by this account',
  `first_viewed_at` datetime(6) NOT NULL COMMENT 'First recorded visit',
  `last_viewed_at` datetime(6) NOT NULL COMMENT 'Most recent recorded visit',
  PRIMARY KEY (`user_id`,`listing_id`),
  KEY `idx_listing_views_user_last_viewed` (`user_id`,`last_viewed_at`),
  KEY `idx_listing_views_listing_id` (`listing_id`),
  CONSTRAINT `fk_listing_views_listing_id` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_listing_views_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_listing_views_view_count` CHECK (`view_count` > 0),
  CONSTRAINT `chk_listing_views_times` CHECK (`last_viewed_at` >= `first_viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `search_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Search history entry ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Signed-in account that searched',
  `source` varchar(10) NOT NULL COMMENT 'home | map',
  `location` varchar(150) DEFAULT NULL COMMENT 'Searched city or address text',
  `check_in` date DEFAULT NULL COMMENT 'Requested check-in date',
  `check_out` date DEFAULT NULL COMMENT 'Requested check-out date',
  `guest_count` int(11) DEFAULT NULL COMMENT 'Requested guest count',
  `property_type_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Requested accommodation type',
  `min_price` decimal(12,2) DEFAULT NULL COMMENT 'Lowest nightly price filter',
  `max_price` decimal(12,2) DEFAULT NULL COMMENT 'Highest nightly price filter',
  `searched_at` datetime(6) NOT NULL COMMENT 'Search time',
  PRIMARY KEY (`id`),
  KEY `idx_search_history_user_searched_at` (`user_id`,`searched_at`),
  KEY `idx_search_history_property_type_id` (`property_type_id`),
  CONSTRAINT `fk_search_history_property_type_id` FOREIGN KEY (`property_type_id`) REFERENCES `property_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_search_history_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_search_history_source` CHECK (`source` in ('home','map')),
  CONSTRAINT `chk_search_history_dates` CHECK (`check_in` is null and `check_out` is null or `check_in` is not null and `check_out` is not null and `check_out` > `check_in`),
  CONSTRAINT `chk_search_history_guest_count` CHECK (`guest_count` is null or `guest_count` > 0),
  CONSTRAINT `chk_search_history_prices` CHECK ((`min_price` is null or `min_price` >= 0) and (`max_price` is null or `max_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nice-to-have content: articles and travel guides
