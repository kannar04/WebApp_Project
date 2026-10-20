-- Keeps the original 11-parameter routine for external/legacy callers.
-- Metadata count is obtained inside this CALL, never from a subsequent PHP query.
DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_listing_search_filtered(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT, IN p_amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_amenity BIGINT UNSIGNED;
    IF p_amenities IS NULL OR NOT JSON_VALID(p_amenities) OR JSON_TYPE(p_amenities)<>'ARRAY' OR JSON_LENGTH(p_amenities)>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc không hợp lệ.';
    END IF;
    WHILE v_i<JSON_LENGTH(p_amenities) DO
        IF JSON_TYPE(JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']')))<>'INTEGER' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ID tiện nghi không hợp lệ.'; END IF;
        SET v_amenity=JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']'));
        IF NOT EXISTS(SELECT 1 FROM amenities WHERE id=v_amenity AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc đã ngừng sử dụng hoặc không tồn tại.'; END IF;
        SET v_i=v_i+1;
    END WHILE;
    IF p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,u.full_name host_name,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND (p_location='' OR l.city LIKE CONCAT('%',p_location,'%') OR l.address LIKE CONCAT('%',p_location,'%') OR l.title LIKE CONCAT('%',p_location,'%'))
        AND (p_guests=0 OR l.max_guests>=p_guests) AND (p_type=0 OR l.property_type_id=p_type)
        AND (p_min=0 OR l.base_nightly_rate>=p_min) AND (p_max=0 OR l.base_nightly_rate<=p_max)
        AND (p_bedrooms=0 OR l.bedroom_count>=p_bedrooms)
        AND NOT EXISTS(SELECT 1 FROM amenities wanted WHERE JSON_CONTAINS(p_amenities,CAST(wanted.id AS CHAR))
            AND NOT EXISTS(SELECT 1 FROM listing_amenities la WHERE la.listing_id=l.id AND la.amenity_id=wanted.id))
        AND (p_start IS NULL OR p_end IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY CASE WHEN p_sort='price_asc' THEN l.base_nightly_rate END ASC,
        CASE WHEN p_sort='price_desc' THEN l.base_nightly_rate END DESC,
        CASE WHEN p_sort='rating' THEN rating END DESC,l.created_at DESC,l.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;
