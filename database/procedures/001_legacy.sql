-- Preserved original team routines; installs missing routines without overwriting existing ones.
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `DST_ sp_list_amenities`()
BEGIN
  SELECT id, name, icon_key
  FROM amenities
  WHERE is_active = 1
  ORDER BY name;
END$$

CREATE PROCEDURE IF NOT EXISTS `DST_sp_get_user_profile`(IN `p_user_id` bigint(20) unsigned)
BEGIN
  SELECT id, full_name, phone, avatar_url, created_at
  FROM users
  WHERE id = p_user_id AND status = 'active';
END$$

CREATE PROCEDURE IF NOT EXISTS `DST_sp_list_property_types`()
BEGIN
  SELECT id, name, description
  FROM property_types
  WHERE is_active = 1
  ORDER BY name;
END$$

CREATE PROCEDURE IF NOT EXISTS `DST_sp_update_profile`(IN `p_user_id` bigint(20) unsigned, IN `p_full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_phone` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_avatar_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  IF p_full_name IS NULL OR TRIM(p_full_name) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Full name is required';
  END IF;

  UPDATE users
  SET full_name = TRIM(p_full_name),
      phone = p_phone,
      avatar_url = p_avatar_url,
      updated_at = UTC_TIMESTAMP(6)
  WHERE id = p_user_id AND status = 'active';

  SELECT ROW_COUNT() AS updated_count;
END$$

CREATE PROCEDURE IF NOT EXISTS `LTP_sp_get_listing_amenities`(IN `p_listing_id` bigint(20) unsigned)
BEGIN
  SELECT a.id, a.name, a.icon_key
  FROM listing_amenities la
  JOIN amenities a ON a.id = la.amenity_id
  JOIN listings l ON l.id = la.listing_id
  WHERE l.id = p_listing_id AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY a.name;
END$$

CREATE PROCEDURE IF NOT EXISTS `LTP_sp_get_listing_photos`(IN `p_listing_id` bigint(20) unsigned)
BEGIN
  SELECT p.id, p.image_url, p.alt_text, p.sort_order
  FROM listing_photos p
  JOIN listings l ON l.id = p.listing_id
  WHERE l.id = p_listing_id AND l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
  ORDER BY p.sort_order, p.id;
END$$

CREATE PROCEDURE IF NOT EXISTS `LTP_sp_list_cancellation_policies`()
BEGIN
  SELECT id, name, description, cutoff_hours,
         early_refund_pct, late_refund_pct, refund_fees
  FROM cancellation_policies
  WHERE is_active = 1
  ORDER BY name;
END$$

CREATE PROCEDURE IF NOT EXISTS `LTP_sp_search_listings_by_city`(IN `p_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
  SELECT l.id, l.title, l.city, l.country_code,
         l.base_nightly_rate, l.fee_amount, l.currency, l.max_guests
  FROM listings l
  WHERE l.moderation_status = 'approved' AND l.is_visible = 1 AND l.deleted_at IS NULL
    AND EXISTS (SELECT 1 FROM users h WHERE h.id = l.host_id AND h.status = 'active')
    AND (p_city IS NULL OR l.city = p_city)
  ORDER BY l.base_nightly_rate, l.id;
END$$

CREATE PROCEDURE IF NOT EXISTS `NMT_sp_add_wishlist_item`(IN `p_user_id` bigint(20) unsigned, IN `p_listing_id` bigint(20) unsigned)
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

CREATE PROCEDURE IF NOT EXISTS `NMT_sp_get_user_notifications`(IN `p_user_id` bigint(20) unsigned)
BEGIN
  SELECT n.id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n
  JOIN users u ON u.id = n.user_id
  WHERE n.user_id = p_user_id AND u.status = 'active' AND n.channel = 'in_app'
  ORDER BY n.created_at DESC, n.id DESC;
END$$

CREATE PROCEDURE IF NOT EXISTS `NMT_sp_mark_notification_read`(IN `p_user_id` bigint(20) unsigned, IN `p_notification_id` bigint(20) unsigned)
BEGIN
  UPDATE notifications
  SET read_at = UTC_TIMESTAMP(6)
  WHERE id = p_notification_id AND user_id = p_user_id
    AND channel = 'in_app' AND read_at IS NULL
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS updated_count;
END$$

CREATE PROCEDURE IF NOT EXISTS `NMT_sp_remove_wishlist_item`(IN `p_user_id` bigint(20) unsigned, IN `p_listing_id` bigint(20) unsigned)
BEGIN
  DELETE FROM wishlist_items
  WHERE user_id = p_user_id AND listing_id = p_listing_id
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS removed_count;
END$$

CREATE PROCEDURE IF NOT EXISTS `NTK_sp_get_guest_bookings`(IN `p_guest_id` bigint(20) unsigned)
BEGIN
  SELECT b.id, l.title, b.check_in, b.check_out, b.guest_count,
         b.status, b.total_amount, b.currency
  FROM bookings b
  JOIN listings l ON l.id = b.listing_id
  JOIN users u ON u.id = b.guest_id
  WHERE b.guest_id = p_guest_id AND u.status = 'active'
  ORDER BY b.created_at DESC, b.id DESC;
END$$

CREATE PROCEDURE IF NOT EXISTS `NTK_sp_get_host_bookings`(IN `p_host_id` bigint(20) unsigned)
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

CREATE PROCEDURE IF NOT EXISTS `NTK_sp_get_listing_reviews`(IN `p_listing_id` bigint(20) unsigned)
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

CREATE PROCEDURE IF NOT EXISTS `NTK_sp_get_wishlist`(IN `p_user_id` bigint(20) unsigned)
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
