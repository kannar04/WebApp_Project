-- Home 2 Home: 16 simple stored procedures
-- Target: MariaDB 10.4.32. Install database-schema.sql first.
-- Focus: short SELECT, JOIN, UPDATE, INSERT and DELETE examples.
-- Account IDs must come from the authenticated backend or an authorized lookup.
-- These routines use SQL SECURITY INVOKER and are for a trusted backend connection.
-- 12 read procedures and 4 small write procedures.
-- Single-statement writes are atomic; writes return row counts or saved status.
-- No booking state, price snapshot or refund is modified by these examples.
-- Installing replaces only the procedure names below; other installed routines remain.
-- Example CALL statements are commented and use sample IDs: replace with your own.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION time_zone = '+00:00';
USE home_2_home;

-- 01. View an active account profile.
DROP PROCEDURE IF EXISTS sp_get_user_profile;
DELIMITER $$
CREATE PROCEDURE sp_get_user_profile(IN p_user_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT id, full_name, phone, avatar_url, created_at
  FROM users
  WHERE id = p_user_id AND status = 'active';
END$$
DELIMITER ;

-- 02. Update account name, phone and avatar.
DROP PROCEDURE IF EXISTS sp_update_profile;
DELIMITER $$
CREATE PROCEDURE sp_update_profile(IN p_user_id BIGINT UNSIGNED, IN p_full_name VARCHAR(150), IN p_phone VARCHAR(25), IN p_avatar_url TEXT)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
  IF p_full_name IS NULL OR TRIM(p_full_name) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Full name is required';
  END IF;
  UPDATE users
  SET full_name = TRIM(p_full_name), phone = p_phone,
      avatar_url = p_avatar_url, updated_at = UTC_TIMESTAMP(6)
  WHERE id = p_user_id AND status = 'active';
  SELECT ROW_COUNT() AS updated_count;
END$$
DELIMITER ;

-- 03. List active accommodation types.
DROP PROCEDURE IF EXISTS sp_list_property_types;
DELIMITER $$
CREATE PROCEDURE sp_list_property_types()
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT id, name, description
  FROM property_types
  WHERE is_active = 1
  ORDER BY name;
END$$
DELIMITER ;

-- 04. List active amenities.
DROP PROCEDURE IF EXISTS sp_list_amenities;
DELIMITER $$
CREATE PROCEDURE sp_list_amenities()
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT id, name, icon_key
  FROM amenities
  WHERE is_active = 1
  ORDER BY name;
END$$
DELIMITER ;

-- 05. List available cancellation policies.
DROP PROCEDURE IF EXISTS sp_list_cancellation_policies;
DELIMITER $$
CREATE PROCEDURE sp_list_cancellation_policies()
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT id, name, description, cutoff_hours,
         early_refund_pct, late_refund_pct, refund_fees
  FROM cancellation_policies
  WHERE is_active = 1
  ORDER BY name;
END$$
DELIMITER ;

-- 06. Find public listings in a city; NULL returns all cities.
DROP PROCEDURE IF EXISTS sp_search_listings_by_city;
DELIMITER $$
CREATE PROCEDURE sp_search_listings_by_city(IN p_city VARCHAR(100))
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT l.id, l.title, l.city, l.country_code,
         l.base_nightly_rate, l.fee_amount, l.currency, l.max_guests
  FROM listings l
  WHERE l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
    AND (p_city IS NULL OR l.city = p_city)
  ORDER BY l.base_nightly_rate, l.id;
END$$
DELIMITER ;

-- 07. View ordered photos for a public listing.
DROP PROCEDURE IF EXISTS sp_get_listing_photos;
DELIMITER $$
CREATE PROCEDURE sp_get_listing_photos(IN p_listing_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT p.id, p.image_url, p.alt_text, p.sort_order
  FROM listing_photos p
  JOIN listings l ON l.id = p.listing_id
  WHERE l.id = p_listing_id AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY p.sort_order, p.id;
END$$
DELIMITER ;

-- 08. View amenities attached to a public listing.
DROP PROCEDURE IF EXISTS sp_get_listing_amenities;
DELIMITER $$
CREATE PROCEDURE sp_get_listing_amenities(IN p_listing_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT a.id, a.name, a.icon_key
  FROM listing_amenities la
  JOIN amenities a ON a.id = la.amenity_id
  JOIN listings l ON l.id = la.listing_id
  WHERE l.id = p_listing_id AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY a.name;
END$$
DELIMITER ;

-- 09. View an active Guest account's booking history.
DROP PROCEDURE IF EXISTS sp_get_guest_bookings;
DELIMITER $$
CREATE PROCEDURE sp_get_guest_bookings(IN p_guest_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT b.id, l.title, b.check_in, b.check_out, b.guest_count,
         b.status, b.total_amount, b.currency
  FROM bookings b
  JOIN listings l ON l.id = b.listing_id
  JOIN users u ON u.id = b.guest_id
  WHERE b.guest_id = p_guest_id AND u.status = 'active'
  ORDER BY b.created_at DESC, b.id DESC;
END$$
DELIMITER ;

-- 10. View booking history for an active Host account.
DROP PROCEDURE IF EXISTS sp_get_host_bookings;
DELIMITER $$
CREATE PROCEDURE sp_get_host_bookings(IN p_host_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT b.id, l.title, b.check_in, b.check_out, b.guest_count,
         b.status, b.total_amount, b.currency
  FROM bookings b
  JOIN listings l ON l.id = b.listing_id
  JOIN users u ON u.id = l.host_id
  WHERE l.host_id = p_host_id AND u.status = 'active'
    AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'host')
  ORDER BY b.created_at DESC, b.id DESC;
END$$
DELIMITER ;

-- 11. View visible reviews for a public listing.
DROP PROCEDURE IF EXISTS sp_get_listing_reviews;
DELIMITER $$
CREATE PROCEDURE sp_get_listing_reviews(IN p_listing_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT r.id, r.rating, r.comment, r.host_reply, r.created_at
  FROM reviews r
  JOIN bookings b ON b.id = r.booking_id
  JOIN listings l ON l.id = b.listing_id
  WHERE l.id = p_listing_id AND r.moderation_status = 'visible'
    AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY r.created_at DESC, r.id DESC;
END$$
DELIMITER ;

-- 12. View the current account's public favorite listings.
DROP PROCEDURE IF EXISTS sp_get_wishlist;
DELIMITER $$
CREATE PROCEDURE sp_get_wishlist(IN p_user_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT l.id, l.title, l.city, l.base_nightly_rate, l.currency
  FROM wishlist_items w
  JOIN listings l ON l.id = w.listing_id
  JOIN users u ON u.id = w.user_id
  WHERE w.user_id = p_user_id AND u.status = 'active'
    AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY w.created_at DESC, l.id DESC;
END$$
DELIMITER ;

-- 13. Save a public listing without creating duplicate favorites.
DROP PROCEDURE IF EXISTS sp_add_wishlist_item;
DELIMITER $$
CREATE PROCEDURE sp_add_wishlist_item(IN p_user_id BIGINT UNSIGNED, IN p_listing_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
  INSERT INTO wishlist_items (user_id, listing_id, created_at)
  SELECT u.id, l.id, UTC_TIMESTAMP(6)
  FROM users u JOIN listings l ON l.id = p_listing_id
  WHERE u.id = p_user_id AND u.status = 'active'
    AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);
  SELECT EXISTS (
    SELECT 1 FROM wishlist_items WHERE user_id = p_user_id AND listing_id = p_listing_id
  ) AS is_saved;
END$$
DELIMITER ;

-- 14. Remove one favorite from an active account.
DROP PROCEDURE IF EXISTS sp_remove_wishlist_item;
DELIMITER $$
CREATE PROCEDURE sp_remove_wishlist_item(IN p_user_id BIGINT UNSIGNED, IN p_listing_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
  DELETE FROM wishlist_items
  WHERE user_id = p_user_id AND listing_id = p_listing_id
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS removed_count;
END$$
DELIMITER ;

-- 15. View an active account's in-app notifications.
DROP PROCEDURE IF EXISTS sp_get_user_notifications;
DELIMITER $$
CREATE PROCEDURE sp_get_user_notifications(IN p_user_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  SELECT n.id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n
  JOIN users u ON u.id = n.user_id
  WHERE n.user_id = p_user_id AND u.status = 'active' AND n.channel = 'in_app'
  ORDER BY n.created_at DESC, n.id DESC;
END$$
DELIMITER ;

-- 16. Mark an owned in-app notification read once.
DROP PROCEDURE IF EXISTS sp_mark_notification_read;
DELIMITER $$
CREATE PROCEDURE sp_mark_notification_read(IN p_user_id BIGINT UNSIGNED, IN p_notification_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
  UPDATE notifications
  SET read_at = UTC_TIMESTAMP(6)
  WHERE id = p_notification_id AND user_id = p_user_id
    AND channel = 'in_app' AND read_at IS NULL
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS updated_count;
END$$
DELIMITER ;

-- Example calls (documentation only):
-- CALL sp_get_user_profile(1);
-- CALL sp_update_profile(1, 'Nguyen An', '0901234567', NULL);
-- CALL sp_list_property_types();
-- CALL sp_list_amenities();
-- CALL sp_list_cancellation_policies();
-- CALL sp_search_listings_by_city('Ho Chi Minh City');
-- CALL sp_get_listing_photos(1);
-- CALL sp_get_listing_amenities(1);
-- CALL sp_get_guest_bookings(1);
-- CALL sp_get_host_bookings(2);
-- CALL sp_get_listing_reviews(1);
-- CALL sp_get_wishlist(1);
-- CALL sp_add_wishlist_item(1, 1);
-- CALL sp_remove_wishlist_item(1, 1);
-- CALL sp_get_user_notifications(1);
-- CALL sp_mark_notification_read(1, 1);
