-- Repair legacy parameter identifiers containing accidental trailing spaces.
-- Argument order and result columns are unchanged; no unrelated routine is removed.
DELIMITER $$

CREATE OR REPLACE PROCEDURE `NMT_sp_add_wishlist_item`(IN p_user_id bigint(20) unsigned, IN p_listing_id bigint(20) unsigned)
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

CREATE OR REPLACE PROCEDURE `NMT_sp_mark_notification_read`(IN p_user_id bigint(20) unsigned, IN p_notification_id bigint(20) unsigned)
BEGIN
  UPDATE notifications
  SET read_at = UTC_TIMESTAMP(6)
  WHERE id = p_notification_id AND user_id = p_user_id
    AND channel = 'in_app' AND read_at IS NULL
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS updated_count;
END$$

CREATE OR REPLACE PROCEDURE `NMT_sp_remove_wishlist_item`(IN p_user_id bigint(20) unsigned, IN p_listing_id bigint(20) unsigned)
BEGIN
  DELETE FROM wishlist_items
  WHERE user_id = p_user_id AND listing_id = p_listing_id
    AND EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active');
  SELECT ROW_COUNT() AS removed_count;
END$$

-- Align a legacy string parameter with the existing table collation, without ALTER TABLE.
CREATE OR REPLACE PROCEDURE LTP_sp_search_listings_by_city(IN p_city VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
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
