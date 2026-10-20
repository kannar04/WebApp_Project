-- LOCAL TESTS ONLY (discovery). Not installed by default and never grant these to the web account.
-- Every routine is limited to rows owned by one random 12-hex test marker.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_test_discovery_listing_state(IN p_listing BIGINT UNSIGNED,
    IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_visible BOOL, IN p_deleted BOOL)
BEGIN
    UPDATE listings SET moderation_status=p_status,is_visible=p_visible,deleted_at=IF(p_deleted,NOW(6),NULL),updated_at=NOW(6)
    WHERE id=p_listing AND title LIKE 'Discovery test %' AND city LIKE 'Discovery QA %';
    SELECT ROW_COUNT() changed;
END$$

CREATE OR REPLACE PROCEDURE sp_test_discovery_views(IN p_user BIGINT UNSIGNED)
BEGIN
    SELECT listing_id,view_count,first_viewed_at,last_viewed_at FROM listing_views WHERE user_id=p_user ORDER BY last_viewed_at DESC,listing_id DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_test_discovery_searches(IN p_user BIGINT UNSIGNED)
BEGIN
    SELECT id,source,location,check_in,check_out,guest_count,property_type_id,min_price,max_price,searched_at
    FROM search_history WHERE user_id=p_user ORDER BY id DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_test_discovery_cleanup(IN p_marker VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_title VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_email VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    IF p_marker IS NULL OR p_marker NOT REGEXP '^[a-f0-9]{12}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing discovery cleanup without an exact fixture marker.';
    END IF;
    SET v_title=CONCAT('Discovery test ',p_marker,' %');
    SET v_email=CONCAT('discovery-test-',p_marker,'-%@home2home.test');
    IF EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE l.title LIKE v_title)
        OR EXISTS(SELECT 1 FROM bookings b JOIN users u ON u.id=b.guest_id WHERE u.email LIKE v_email) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing discovery cleanup for fixtures with bookings.';
    END IF;
    DELETE v FROM listing_views v JOIN listings l ON l.id=v.listing_id WHERE l.title LIKE v_title;
    DELETE v FROM listing_views v JOIN users u ON u.id=v.user_id WHERE u.email LIKE v_email;
    DELETE s FROM search_history s JOIN users u ON u.id=s.user_id WHERE u.email LIKE v_email;
    DELETE e FROM listing_moderation_events e JOIN listings l ON l.id=e.listing_id WHERE l.title LIKE v_title;
    DELETE p FROM listing_photos p JOIN listings l ON l.id=p.listing_id WHERE l.title LIKE v_title;
    DELETE a FROM listing_amenities a JOIN listings l ON l.id=a.listing_id WHERE l.title LIKE v_title;
    DELETE a FROM listing_availability a JOIN listings l ON l.id=a.listing_id WHERE l.title LIKE v_title;
    DELETE w FROM wishlist_items w JOIN listings l ON l.id=w.listing_id WHERE l.title LIKE v_title;
    DELETE g FROM admin_audit_logs g JOIN listings l ON l.id=g.entity_id WHERE g.entity_type='listing' AND l.title LIKE v_title;
    DELETE FROM listings WHERE title LIKE v_title;
    DELETE g FROM admin_audit_logs g JOIN users u ON u.id=g.admin_id WHERE u.email LIKE v_email;
    DELETE w FROM wishlist_items w JOIN users u ON u.id=w.user_id WHERE u.email LIKE v_email;
    DELETE r FROM user_roles r JOIN users u ON u.id=r.user_id WHERE u.email LIKE v_email;
    DELETE FROM users WHERE email LIKE v_email;
    SELECT (SELECT COUNT(*) FROM listings WHERE title LIKE v_title) remaining_listings,
        (SELECT COUNT(*) FROM users WHERE email LIKE v_email) remaining_users;
END$$

DELIMITER ;
