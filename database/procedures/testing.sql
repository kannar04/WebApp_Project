-- LOCAL TESTS ONLY. Not installed by default and never grant these to the web account.
DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_test_booking_inspect(IN p_id BIGINT UNSIGNED)
BEGIN
    SELECT b.status,b.policy_snapshot,(SELECT COUNT(*) FROM booking_nights WHERE booking_id=b.id) nights,
        (SELECT COUNT(*) FROM booking_events WHERE booking_id=b.id) events,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id) notifications FROM bookings b WHERE b.id=p_id;
END$$
CREATE OR REPLACE PROCEDURE sp_test_listing_inspect(IN p_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    SELECT l.id,l.title,l.address,l.base_nightly_rate,l.moderation_status,
        (SELECT COUNT(*) FROM listing_photos WHERE listing_id=l.id) photo_count,
        (SELECT COUNT(*) FROM bookings WHERE listing_id=l.id) booking_count,
        (SELECT id FROM bookings WHERE listing_id=l.id ORDER BY id DESC LIMIT 1) booking_id,
        (SELECT status FROM bookings WHERE listing_id=l.id ORDER BY id DESC LIMIT 1) booking_status,
        (SELECT COUNT(*) FROM listing_amenities WHERE listing_id=l.id) amenity_count,
        (SELECT COUNT(*) FROM listing_moderation_events WHERE listing_id=l.id) moderation_events
    FROM listings l WHERE title=p_title AND city='Audit' AND title LIKE 'HTTP audit %';
END$$
CREATE OR REPLACE PROCEDURE sp_test_listing_approve(IN p_id BIGINT UNSIGNED)
BEGIN
    UPDATE listings SET moderation_status='approved' WHERE id=p_id AND city='Audit' AND title LIKE 'Audit fixture %';
END$$
CREATE OR REPLACE PROCEDURE sp_test_moderation_actions(IN p_id BIGINT UNSIGNED)
BEGIN SELECT action FROM listing_moderation_events WHERE listing_id=p_id ORDER BY id; END$$
CREATE OR REPLACE PROCEDURE sp_test_local_photos()
BEGIN SELECT image_url FROM listing_photos WHERE image_url LIKE '/assets/images/demo/%'; END$$
CREATE OR REPLACE PROCEDURE sp_test_orphan_photos()
BEGIN SELECT COUNT(*) FROM listing_photos p LEFT JOIN listings l ON l.id=p.listing_id WHERE l.id IS NULL; END$$
CREATE OR REPLACE PROCEDURE sp_test_seed_counts()
BEGIN SELECT (SELECT COUNT(*) FROM listing_photos) photos,(SELECT COUNT(*) FROM amenities WHERE is_active=1) amenities,
    (SELECT COUNT(*) FROM admin_audit_logs) admin_audits,
    (SELECT COUNT(*) FROM admin_audit_logs WHERE action='save_catalog') catalog_audits,
    (SELECT COUNT(*) FROM admin_audit_logs WHERE action='resolve_report') report_audits,
    (SELECT COUNT(*) FROM admin_audit_logs WHERE action='moderate_review') review_audits; END$$
CREATE OR REPLACE PROCEDURE sp_test_listing_ids(IN p_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_city VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN SELECT id FROM listings WHERE title=p_title AND city=p_city AND city='Audit' AND title LIKE 'HTTP audit %'; END$$
CREATE OR REPLACE PROCEDURE sp_test_user_id(IN p_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN SELECT id FROM users WHERE email=p_email AND email LIKE 'http-audit-%@home2home.test'; END$$
CREATE OR REPLACE PROCEDURE sp_test_booking_ids(IN p_listing BIGINT UNSIGNED)
BEGIN SELECT b.id FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE l.id=p_listing AND l.city='Audit' AND l.title LIKE 'HTTP audit %'; END$$
CREATE OR REPLACE PROCEDURE sp_test_cleanup_booking(IN p_id BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN users u ON u.id=b.guest_id JOIN listings l ON l.id=b.listing_id WHERE b.id=p_id
        AND b.check_in>CURDATE()+INTERVAL 2000 DAY AND (u.email IN ('guest@home2home.test','admin@home2home.test')
        OR (l.city='Audit' AND l.title LIKE 'HTTP audit %' AND u.email=CONCAT('http-audit-',SUBSTRING(l.title,12),'-guest@home2home.test')))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture booking.';
    END IF;
    DELETE FROM notifications WHERE booking_id=p_id;
    DELETE FROM reviews WHERE booking_id=p_id;
    DELETE FROM booking_cancellations WHERE booking_id=p_id;
    DELETE FROM booking_events WHERE booking_id=p_id;
    DELETE FROM booking_nights WHERE booking_id=p_id;
    DELETE FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=p_id;
    DELETE FROM bookings WHERE id=p_id;
END$$
CREATE OR REPLACE PROCEDURE sp_test_cleanup_listing(IN p_id BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings WHERE id=p_id AND city='Audit' AND (title LIKE 'Audit fixture %' OR title LIKE 'HTTP audit %'))
       OR EXISTS(SELECT 1 FROM bookings WHERE listing_id=p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture listing.';
    END IF;
    DELETE FROM listing_moderation_events WHERE listing_id=p_id;
    DELETE FROM listing_photos WHERE listing_id=p_id;
    DELETE FROM listing_amenities WHERE listing_id=p_id;
    DELETE FROM listing_availability WHERE listing_id=p_id;
    DELETE FROM wishlist_items WHERE listing_id=p_id;
    DELETE FROM admin_audit_logs WHERE entity_type='listing' AND entity_id=p_id;
    DELETE FROM listings WHERE id=p_id;
END$$
CREATE OR REPLACE PROCEDURE sp_test_cleanup_user(IN p_id BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_id AND (email LIKE 'audit-%@home2home.test' OR email LIKE 'http-audit-%@home2home.test'))
       OR EXISTS(SELECT 1 FROM bookings WHERE guest_id=p_id) OR EXISTS(SELECT 1 FROM listings WHERE host_id=p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture user.';
    END IF;
    DELETE FROM admin_audit_logs WHERE admin_id=p_id;
    DELETE FROM user_roles WHERE user_id=p_id;
    DELETE FROM users WHERE id=p_id;
END$$
CREATE OR REPLACE PROCEDURE sp_test_historical_booking(IN p_1 bigint unsigned, IN p_2 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_test_historical_booking; RELEASE SAVEPOINT h2h_test_historical_booking; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_test_historical_booking; END IF;
    
    IF DATABASE() NOT REGEXP '^db_home2home_schema_test_[a-f0-9]{12}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical fixtures are restricted to the isolated schema test database.'; END IF;
    INSERT INTO bookings(listing_id,guest_id,check_in,check_out,guest_count,status,currency,fee_amount,total_amount,policy_snapshot,created_at,updated_at)
        SELECT l.id,p_2,CURDATE()-INTERVAL 4 DAY,CURDATE()-INTERVAL 2 DAY,1,'completed',l.currency,l.fee_amount,l.base_nightly_rate*2+l.fee_amount,
            JSON_OBJECT('name',cp.name,'cutoff_hours',cp.cutoff_hours,'early_refund_pct',cp.early_refund_pct,'late_refund_pct',cp.late_refund_pct,'refund_fees',cp.refund_fees,'check_in_time',CAST(l.check_in_time AS CHAR),'time_zone',l.time_zone),NOW(6),NOW(6)
        FROM listings l JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id WHERE l.id=p_1;
    SET v_id=LAST_INSERT_ID();
    INSERT INTO booking_nights(booking_id,stay_date,nightly_amount) SELECT v_id,CURDATE()-INTERVAL 4 DAY,base_nightly_rate FROM listings WHERE id=p_1;
    INSERT INTO booking_nights(booking_id,stay_date,nightly_amount) SELECT v_id,CURDATE()-INTERVAL 3 DAY,base_nightly_rate FROM listings WHERE id=p_1;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(v_id,p_2,'confirmed','completed','Historical test fixture',NOW(6));
    SELECT v_id booking_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_test_historical_booking; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_test_historical_confirmed(IN p_booking BIGINT UNSIGNED)
BEGIN
    IF DATABASE() NOT REGEXP '^db_home2home_schema_test_[a-f0-9]{12}$'
        OR NOT EXISTS(SELECT 1 FROM booking_events WHERE booking_id=p_booking AND reason='Historical test fixture') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical state fixture requires an owned isolated test booking.';
    END IF;
    UPDATE bookings SET status='confirmed' WHERE id=p_booking AND status='completed';
    UPDATE booking_events SET from_status='pending',to_status='confirmed' WHERE booking_id=p_booking AND reason='Historical test fixture';
END$$

CREATE OR REPLACE PROCEDURE sp_test_expire_reset_token(IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    IF DATABASE() NOT REGEXP '^db_home2home_schema_test_[a-f0-9]{12}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Expiry fixtures require the owned isolated schema.'; END IF;
    UPDATE password_reset_tokens SET created_at=NOW(6)-INTERVAL 2 HOUR,expires_at=NOW(6)-INTERVAL 1 HOUR WHERE token_hash=p_hash;
END$$

DELIMITER ;
