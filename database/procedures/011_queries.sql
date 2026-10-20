-- Replaces dynamic PHP query builders with static predicates and sorting.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_user_search(IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    SELECT u.id,u.email,u.full_name,u.phone,u.status,u.created_at,GROUP_CONCAT(ur.role ORDER BY ur.role) roles
    FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id
    WHERE u.deleted_at IS NULL AND (p_query='' OR u.email LIKE CONCAT('%',p_query,'%') OR u.full_name LIKE CONCAT('%',p_query,'%'))
    GROUP BY u.id ORDER BY u.created_at DESC,u.id DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_user_admin_update(IN p_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_phone VARCHAR(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_affected INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_profile; RELEASE SAVEPOINT h2h_admin_profile; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_profile; END IF;
    IF TRIM(p_email)='' OR TRIM(p_name)='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Email và họ tên là bắt buộc.'; END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_id AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không tồn tại.'; END IF;
    UPDATE users SET email=LOWER(TRIM(p_email)),full_name=TRIM(p_name),phone=p_phone,
        password_hash=COALESCE(p_hash,password_hash),updated_at=NOW(6) WHERE id=p_id AND deleted_at IS NULL;
    SET v_affected=ROW_COUNT();
    IF p_hash IS NOT NULL THEN UPDATE password_reset_tokens SET used_at=NOW(6) WHERE user_id=p_id AND used_at IS NULL; END IF;
    SELECT v_affected __affected,LAST_INSERT_ID() __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_profile; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_find_for_user(IN p_id BIGINT UNSIGNED, IN p_user BIGINT UNSIGNED, IN p_admin BOOL)
BEGIN
    SELECT b.id,b.listing_id,b.guest_id,b.check_in,b.check_out,b.guest_count,b.status,b.currency,b.fee_amount,b.total_amount,b.policy_snapshot,b.created_at,b.updated_at,l.title,l.address,l.city,l.host_id,u.full_name guest_name,h.full_name host_name
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id
    WHERE b.id=p_id AND EXISTS(SELECT 1 FROM users a WHERE a.id=p_user AND a.status='active')
      AND (b.guest_id=p_user OR l.host_id=p_user OR (p_admin=1 AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_user AND role='admin')));
END$$

CREATE OR REPLACE PROCEDURE sp_listing_search(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT)
BEGIN
    IF p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,u.full_name host_name,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND (p_location='' OR l.city LIKE CONCAT('%',p_location,'%') OR l.address LIKE CONCAT('%',p_location,'%') OR l.title LIKE CONCAT('%',p_location,'%'))
        AND (p_guests=0 OR l.max_guests>=p_guests) AND (p_type=0 OR l.property_type_id=p_type)
        AND (p_min=0 OR l.base_nightly_rate>=p_min) AND (p_max=0 OR l.base_nightly_rate<=p_max)
        AND (p_bedrooms=0 OR l.bedroom_count>=p_bedrooms)
        AND (p_start IS NULL OR p_end IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY CASE WHEN p_sort='price_asc' THEN l.base_nightly_rate END ASC,
        CASE WHEN p_sort='price_desc' THEN l.base_nightly_rate END DESC,
        CASE WHEN p_sort='rating' THEN rating END DESC,l.created_at DESC,l.id DESC
    LIMIT p_limit OFFSET p_offset;
END$$
DELIMITER ;
