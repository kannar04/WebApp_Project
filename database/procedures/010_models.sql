-- Fixed statements and typed parameters; no dynamic SQL.
DELIMITER $$

CREATE OR REPLACE PROCEDURE `sp_user_get_by_email`(IN p_1 varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    SELECT id,email,password_hash,full_name,phone,avatar_url,status,created_at,updated_at,deleted_at FROM users WHERE email = p_1 LIMIT 1;
END$$

CREATE OR REPLACE PROCEDURE `sp_user_get_with_roles`(IN p_1 bigint unsigned)
BEGIN
    SELECT u.id,u.email,u.password_hash,u.full_name,u.phone,u.avatar_url,u.status,u.created_at,u.updated_at,u.deleted_at, GROUP_CONCAT(ur.role ORDER BY ur.role) AS role_list FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id WHERE u.id = p_1 AND u.deleted_at IS NULL GROUP BY u.id;
END$$

CREATE OR REPLACE PROCEDURE `sp_user_get_for_admin`(IN p_1 bigint unsigned)
BEGIN
    SELECT u.id,u.email,u.full_name,u.phone,u.status,GROUP_CONCAT(ur.role ORDER BY ur.role) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=p_1 AND u.deleted_at IS NULL GROUP BY u.id;
END$$

CREATE OR REPLACE PROCEDURE `sp_user_grant_host`(IN p_1 bigint unsigned)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_1 AND status='active' AND deleted_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không khả dụng.'; END IF;
    INSERT IGNORE INTO user_roles (user_id, role, granted_at) VALUES (p_1, 'host', NOW(6));
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_user_set_status`(IN p_1 varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 bigint unsigned)
BEGIN
    IF p_1 IS NULL OR p_1 NOT IN ('active','suspended') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái tài khoản không hợp lệ.'; END IF;
    UPDATE users SET status = p_1 WHERE id = p_2 AND deleted_at IS NULL;
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_user_soft_delete`(IN p_1 bigint unsigned)
BEGIN
    UPDATE users SET status='deleted',deleted_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_get_public`(IN p_1 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, l.smoking_allowed allows_smoking, l.pets_allowed allows_pets, l.parties_allowed allows_parties, pt.name property_type, cp.name cancellation_name, cp.description cancellation_description, cp.cutoff_hours, cp.early_refund_pct refund_percent, u.full_name host_name, u.avatar_url host_avatar, u.created_at host_since FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id JOIN users u ON u.id=l.host_id WHERE l.id=p_1 AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_get_owned`(IN p_1 bigint unsigned, IN p_2 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, l.smoking_allowed allows_smoking, l.pets_allowed allows_pets, l.parties_allowed allows_parties FROM listings l WHERE id=p_1 AND host_id=p_2 AND deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_get_by_host`(IN p_1 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, pt.name property_type, (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url FROM listings l JOIN property_types pt ON pt.id=l.property_type_id WHERE l.host_id=p_1 AND l.deleted_at IS NULL ORDER BY l.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_create`(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 bigint unsigned, IN p_4 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_6 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_7 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_8 decimal(12,2), IN p_9 decimal(12,2), IN p_10 int, IN p_11 int, IN p_12 int, IN p_13 int, IN p_14 time, IN p_15 time, IN p_16 bool, IN p_17 bool, IN p_18 bool)
BEGIN
    IF p_8 IS NULL OR p_8<=0 OR p_9 IS NULL OR p_9<0 OR p_10<1 OR p_11<1 OR p_12<1 OR p_13<1
        OR p_4 IS NULL OR TRIM(p_4)='' OR p_5 IS NULL OR TRIM(p_5)='' OR p_6 IS NULL OR TRIM(p_6)='' OR p_7 IS NULL OR TRIM(p_7)=''
        OR NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_1 AND u.status='active' AND u.deleted_at IS NULL AND r.role='host')
        OR NOT EXISTS(SELECT 1 FROM property_types WHERE id=p_2 AND is_active=1)
        OR NOT EXISTS(SELECT 1 FROM cancellation_policies WHERE id=p_3 AND is_active=1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin hoặc quyền tạo chỗ ở không hợp lệ.';
    END IF;
    INSERT INTO listings (host_id, property_type_id, cancellation_policy_id, title, address, city, country_code, time_zone, description, base_nightly_rate, fee_amount, currency, max_guests, room_count, bedroom_count, bed_count, check_in_time, check_out_time, smoking_allowed, pets_allowed, parties_allowed, moderation_status, is_visible, created_at, updated_at) VALUES (p_1,p_2,p_3,p_4,p_5,p_6,'VN','Asia/Ho_Chi_Minh',p_7,p_8,p_9,'VND',p_10,p_11,p_12,p_13,p_14,p_15,p_16,p_17,p_18,'pending',1,NOW(6),NOW(6));
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_update`(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_6 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_7 decimal(12,2), IN p_8 decimal(12,2), IN p_9 int, IN p_10 int, IN p_11 int, IN p_12 int, IN p_13 time, IN p_14 time, IN p_15 bool, IN p_16 bool, IN p_17 bool, IN p_18 bigint unsigned, IN p_19 bigint unsigned)
BEGIN
    IF p_7 IS NULL OR p_7<=0 OR p_8 IS NULL OR p_8<0 OR p_9<1 OR p_10<1 OR p_11<1 OR p_12<1
        OR p_3 IS NULL OR TRIM(p_3)='' OR p_4 IS NULL OR TRIM(p_4)='' OR p_5 IS NULL OR TRIM(p_5)='' OR p_6 IS NULL OR TRIM(p_6)=''
        OR NOT EXISTS(SELECT 1 FROM listings l JOIN users u ON u.id=l.host_id JOIN user_roles r ON r.user_id=u.id WHERE l.id=p_18 AND l.host_id=p_19 AND l.deleted_at IS NULL AND u.status='active' AND r.role='host')
        OR NOT EXISTS(SELECT 1 FROM property_types WHERE id=p_1 AND is_active=1)
        OR NOT EXISTS(SELECT 1 FROM cancellation_policies WHERE id=p_2 AND is_active=1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin hoặc quyền sửa chỗ ở không hợp lệ.';
    END IF;
    UPDATE listings SET property_type_id=p_1, cancellation_policy_id=p_2, title=p_3, address=p_4, city=p_5, description=p_6, base_nightly_rate=p_7, fee_amount=p_8, max_guests=p_9, room_count=p_10, bedroom_count=p_11, bed_count=p_12, check_in_time=p_13, check_out_time=p_14, smoking_allowed=p_15, pets_allowed=p_16, parties_allowed=p_17, moderation_status='pending', updated_at=NOW(6) WHERE id=p_18 AND host_id=p_19 AND deleted_at IS NULL;
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_set_visible`(IN p_1 bool, IN p_2 bigint unsigned, IN p_3 bigint unsigned)
BEGIN
    IF p_1 IS NULL OR p_1 NOT IN (0,1) OR NOT EXISTS(SELECT 1 FROM listings l JOIN users u ON u.id=l.host_id JOIN user_roles r ON r.user_id=u.id WHERE l.id=p_2 AND l.host_id=p_3 AND l.deleted_at IS NULL AND u.status='active' AND r.role='host') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thay đổi hiển thị chỗ ở.';
    END IF;
    UPDATE listings SET is_visible=p_1 WHERE id=p_2 AND host_id=p_3;
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_availability_get`(IN p_1 bigint unsigned)
BEGIN
    SELECT la.listing_id,la.stay_date,la.is_open,la.block_reason,la.updated_at,CASE WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=la.listing_id AND b.status='confirmed' AND b.check_in<=la.stay_date AND b.check_out>la.stay_date) THEN 1 ELSE 0 END booked FROM listing_availability la WHERE la.listing_id=p_1 AND la.stay_date>=CURDATE() ORDER BY la.stay_date LIMIT 120;
END$$

CREATE OR REPLACE PROCEDURE `sp_listing_amenity_ids`(IN p_1 bigint unsigned)
BEGIN
    SELECT amenity_id FROM listing_amenities WHERE listing_id=p_1;
END$$

CREATE OR REPLACE PROCEDURE `NTK_sp_get_listing_reviews`(IN p_1 bigint unsigned)
BEGIN
    SELECT r.id,r.booking_id,r.rating,r.comment,r.host_reply,r.host_replied_at,r.moderation_status,r.created_at,r.updated_at,r.host_reply host_response,u.full_name guest_name FROM reviews r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id WHERE b.listing_id=p_1 AND r.moderation_status='visible' AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND EXISTS(SELECT 1 FROM users WHERE id=l.host_id AND status='active') ORDER BY r.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `NTK_sp_get_guest_bookings`(IN p_1 bigint unsigned)
BEGIN
    SELECT b.id,b.listing_id,b.guest_id,b.check_in,b.check_out,b.guest_count,b.status,b.currency,b.fee_amount,b.total_amount,b.policy_snapshot,b.created_at,b.updated_at,l.title,l.city,(SELECT image_url FROM listing_photos WHERE listing_id=l.id AND EXISTS(SELECT 1 FROM users WHERE id=b.guest_id AND status='active') ORDER BY sort_order,id LIMIT 1) image_url FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.guest_id=p_1 AND EXISTS(SELECT 1 FROM users WHERE id=b.guest_id AND status='active') ORDER BY b.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `NTK_sp_get_host_bookings`(IN p_1 bigint unsigned)
BEGIN
    SELECT b.id,b.listing_id,b.guest_id,b.check_in,b.check_out,b.guest_count,b.status,b.currency,b.fee_amount,b.total_amount,b.policy_snapshot,b.created_at,b.updated_at,l.title,u.full_name guest_name,CASE WHEN b.status IN ('confirmed','completed') THEN u.phone ELSE NULL END guest_phone FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id WHERE l.host_id=p_1 AND EXISTS(SELECT 1 FROM users WHERE id=l.host_id AND status='active') AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=l.host_id AND role='host') ORDER BY b.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `sp_booking_get_all`()
BEGIN
    SELECT b.id,b.listing_id,b.guest_id,b.check_in,b.check_out,b.guest_count,b.status,b.currency,b.fee_amount,b.total_amount,b.policy_snapshot,b.created_at,b.updated_at,l.title,u.full_name guest_name,h.full_name host_name FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id ORDER BY b.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_count_users`()
BEGIN
    SELECT COUNT(*) FROM users WHERE deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_count_hosts`()
BEGIN
    SELECT COUNT(*) FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='host' AND u.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_count_listings`()
BEGIN
    SELECT COUNT(*) FROM listings WHERE deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_count_bookings`()
BEGIN
    SELECT COUNT(*) FROM bookings;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_total_revenue`()
BEGIN
    SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status='completed';
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_listings`()
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.moderation_reason moderation_note,u.full_name host_name,pt.name property_type FROM listings l JOIN users u ON u.id=l.host_id JOIN property_types pt ON pt.id=l.property_type_id WHERE l.deleted_at IS NULL ORDER BY l.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_audit_add`(IN p_1 bigint unsigned, IN p_2 varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 bigint unsigned, IN p_5 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    CALL sp_admin_assert_role(p_1);
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_1,p_2,p_3,p_4,p_5,NOW(6));
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_admin_listing_find`(IN p_1 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee FROM listings l WHERE id=p_1 AND deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE `sp_host_stats`(IN p_1 bigint unsigned)
BEGIN
    SELECT COUNT(DISTINCT CASE WHEN l.deleted_at IS NULL THEN l.id END) listings,COUNT(DISTINCT b.id) bookings,COALESCE(SUM(b.status='completed'),0) completed,COALESCE(SUM(CASE WHEN b.status='completed' THEN b.total_amount ELSE 0 END),0) revenue FROM listings l LEFT JOIN bookings b ON b.listing_id=l.id WHERE l.host_id=p_1;
END$$

CREATE OR REPLACE PROCEDURE `sp_wishlist_contains`(IN p_1 bigint unsigned, IN p_2 bigint unsigned)
BEGIN
    SELECT 1 FROM wishlist_items WHERE user_id=p_1 AND listing_id=p_2;
END$$

CREATE OR REPLACE PROCEDURE `sp_wishlist_ids`(IN p_1 bigint unsigned)
BEGIN
    SELECT listing_id FROM wishlist_items WHERE user_id=p_1;
END$$

CREATE OR REPLACE PROCEDURE `NTK_sp_get_wishlist`(IN p_1 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,(SELECT image_url FROM listing_photos WHERE listing_id=l.id AND EXISTS(SELECT 1 FROM users WHERE id=l.host_id AND status='active') ORDER BY sort_order,id LIMIT 1) image_url FROM wishlist_items w JOIN listings l ON l.id=w.listing_id JOIN property_types pt ON pt.id=l.property_type_id WHERE w.user_id=p_1 AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND EXISTS(SELECT 1 FROM users WHERE id=w.user_id AND status='active') AND EXISTS(SELECT 1 FROM users WHERE id=l.host_id AND status='active') ORDER BY w.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE `sp_review_create`(IN p_1 smallint, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 bigint unsigned, IN p_4 bigint unsigned)
BEGIN
    IF p_1 IS NULL OR p_1<1 OR p_1>5 OR p_2 IS NULL OR TRIM(p_2)='' OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_4 AND status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đánh giá không hợp lệ.';
    END IF;
    INSERT INTO reviews(booking_id,rating,comment,moderation_status,created_at,updated_at) SELECT b.id,p_1,p_2,'visible',NOW(6),NOW(6) FROM bookings b WHERE b.id=p_3 AND b.guest_id=p_4 AND b.status='completed' AND b.check_out<=CURDATE() AND NOT EXISTS(SELECT 1 FROM reviews WHERE booking_id=b.id);
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE `sp_demo_listing_lock`(IN p_1 varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    SELECT l.id FROM listings l JOIN users u ON u.id=l.host_id WHERE u.email=p_1 AND l.title=p_2 AND l.city=p_3 AND l.deleted_at IS NULL FOR UPDATE;
END$$

CREATE OR REPLACE PROCEDURE `sp_demo_photo_paths`(IN p_1 bigint unsigned)
BEGIN
    SELECT image_url FROM listing_photos WHERE listing_id=p_1;
END$$

CREATE OR REPLACE PROCEDURE `sp_demo_photo_orders`(IN p_1 bigint unsigned)
BEGIN
    SELECT sort_order FROM listing_photos WHERE listing_id=p_1;
END$$

CREATE OR REPLACE PROCEDURE `sp_demo_photo_insert`(IN p_1 bigint unsigned, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 int)
BEGIN
    INSERT INTO listing_photos(listing_id,image_url,alt_text,sort_order,created_at) VALUES(p_1,p_2,p_3,p_4,NOW(6));
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$

DELIMITER ;
