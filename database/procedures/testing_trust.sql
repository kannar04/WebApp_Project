-- LOCAL TESTS ONLY (trust feature). Installed only with the explicit test opt-in; never grant to the web account.
-- Every fixture is limited to isolated schema-test or per-workspace databases and to tagged audit-trust rows.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_test_trust_assert_database()
BEGIN
    IF DATABASE() NOT REGEXP '^(db_home2home_schema_test_[a-f0-9]{12}|db_h2h_ws_[a-z0-9_]+)$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trust fixtures are restricted to isolated test or workspace databases.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_test_trust_completed_booking(IN p_listing BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED, IN p_days_ago INT)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_test_trust_stay; RELEASE SAVEPOINT h2h_test_trust_stay; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_test_trust_stay; END IF;
    CALL sp_test_trust_assert_database();
    IF p_days_ago IS NULL OR p_days_ago<3 OR p_days_ago>900
        OR NOT EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND city='Audit' AND title LIKE 'Audit fixture trust %')
        OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND email LIKE 'audit-trust-%@home2home.test') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Completed-stay fixture requires a tagged trust listing and guest.';
    END IF;
    -- Requested five days before arrival, so stays older than a year fall outside the cancellation window.
    INSERT INTO bookings(listing_id,guest_id,check_in,check_out,guest_count,status,currency,fee_amount,total_amount,policy_snapshot,created_at,updated_at)
        SELECT l.id,p_guest,CURDATE()-INTERVAL p_days_ago DAY,CURDATE()-INTERVAL (p_days_ago-2) DAY,1,'completed',l.currency,l.fee_amount,
            l.base_nightly_rate*2+l.fee_amount,
            JSON_OBJECT('name',cp.name,'cutoff_hours',cp.cutoff_hours,'early_refund_pct',cp.early_refund_pct,'late_refund_pct',cp.late_refund_pct,
                'refund_fees',cp.refund_fees,'check_in_time',CAST(l.check_in_time AS CHAR),'time_zone',l.time_zone),
            NOW(6)-INTERVAL (p_days_ago+5) DAY,NOW(6)-INTERVAL (p_days_ago-2) DAY
        FROM listings l JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id WHERE l.id=p_listing;
    SET v_id=LAST_INSERT_ID();
    INSERT INTO booking_nights(booking_id,stay_date,nightly_amount)
        SELECT v_id,CURDATE()-INTERVAL p_days_ago DAY,base_nightly_rate FROM listings WHERE id=p_listing;
    INSERT INTO booking_nights(booking_id,stay_date,nightly_amount)
        SELECT v_id,CURDATE()-INTERVAL (p_days_ago-1) DAY,base_nightly_rate FROM listings WHERE id=p_listing;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at)
        VALUES(v_id,p_guest,'confirmed','completed','Trust test fixture',NOW(6)-INTERVAL (p_days_ago-2) DAY);
    SELECT v_id booking_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_test_trust_stay; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_test_trust_verification_inspect(IN p_id BIGINT UNSIGNED)
BEGIN
    CALL sp_test_trust_assert_database();
    SELECT v.user_id,v.id_number,v.status,v.reviewed_by,v.decision_reason,
        (SELECT COUNT(*) FROM admin_audit_logs a WHERE a.entity_type='identity_verification' AND a.entity_id=v.id) audit_count,
        (SELECT a.change_summary FROM admin_audit_logs a WHERE a.entity_type='identity_verification' AND a.entity_id=v.id ORDER BY a.id DESC LIMIT 1) audit_summary,
        (SELECT COUNT(*) FROM notifications n WHERE n.user_id=v.user_id AND n.event_type LIKE 'identity\_%') notification_count
    FROM identity_verifications v JOIN users u ON u.id=v.user_id
    WHERE v.id=p_id AND u.email LIKE 'audit-trust-%@home2home.test';
END$$

CREATE OR REPLACE PROCEDURE sp_test_trust_cleanup(IN p_tag VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_users VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_titles VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_test_trust_cleanup; RELEASE SAVEPOINT h2h_test_trust_cleanup; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_test_trust_cleanup; END IF;
    CALL sp_test_trust_assert_database();
    IF p_tag IS NULL OR p_tag NOT REGEXP '^[a-f0-9]{12}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to clean up without a 12-hex trust fixture tag.';
    END IF;
    SET v_users=CONCAT('audit-trust-',p_tag,'-%@home2home.test'),v_titles=CONCAT('Audit fixture trust ',p_tag,'%');
    -- Bookings on tagged listings (tagged guests only ever book tagged listings).
    DELETE n FROM notifications n JOIN bookings b ON b.id=n.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE r FROM reviews r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE c FROM booking_cancellations c JOIN bookings b ON b.id=c.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE e FROM booking_events e JOIN bookings b ON b.id=e.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE bn FROM booking_nights bn JOIN bookings b ON b.id=bn.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE a FROM admin_audit_logs a JOIN bookings b ON a.entity_type='booking' AND a.entity_id=b.id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE a FROM admin_audit_logs a JOIN reviews r ON a.entity_type='review' AND a.entity_id=r.id JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE b FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    -- Tagged listings.
    DELETE g FROM listing_checkin_guides g JOIN listings l ON l.id=g.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE x FROM listing_moderation_events x JOIN listings l ON l.id=x.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE x FROM listing_photos x JOIN listings l ON l.id=x.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE x FROM listing_amenities x JOIN listings l ON l.id=x.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE x FROM listing_availability x JOIN listings l ON l.id=x.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE x FROM wishlist_items x JOIN listings l ON l.id=x.listing_id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE a FROM admin_audit_logs a JOIN listings l ON a.entity_type='listing' AND a.entity_id=l.id WHERE l.city='Audit' AND l.title LIKE v_titles;
    DELETE FROM listings WHERE city='Audit' AND title LIKE v_titles;
    -- Tagged accounts and everything the trust feature stored for them.
    DELETE a FROM admin_audit_logs a JOIN identity_verifications v ON a.entity_type='identity_verification' AND a.entity_id=v.id
        JOIN users u ON u.id=v.user_id WHERE u.email LIKE v_users;
    DELETE n FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.email LIKE v_users;
    DELETE v FROM identity_verifications v JOIN users u ON u.id=v.user_id WHERE u.email LIKE v_users;
    DELETE w FROM wishlist_items w JOIN users u ON u.id=w.user_id WHERE u.email LIKE v_users;
    DELETE s FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.email LIKE v_users;
    DELETE a FROM admin_audit_logs a JOIN users u ON u.id=a.admin_id WHERE u.email LIKE v_users;
    DELETE r FROM user_roles r JOIN users u ON u.id=r.user_id WHERE u.email LIKE v_users;
    DELETE FROM users WHERE email LIKE v_users;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_test_trust_cleanup; END IF;
END$$

DELIMITER ;
