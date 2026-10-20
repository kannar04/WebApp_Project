-- Nice-to-have discovery: map search, recommendations.
-- Map search is read-only. History rows belong to one signed-in account, are trimmed here and cleared only by their owner.
DELIMITER $$

-- All four bounds NULL means "no box" (first fit of a searched place); west > east is a box crossing the antimeridian.
CREATE OR REPLACE PROCEDURE sp_discovery_map_search(
    IN p_north DECIMAL(10,6), IN p_south DECIMAL(10,6), IN p_east DECIMAL(10,6), IN p_west DECIMAL(10,6),
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_guests INT, IN p_type BIGINT UNSIGNED,
    IN p_min DECIMAL(12,2), IN p_max DECIMAL(12,2), IN p_start DATE, IN p_end DATE, IN p_limit INT)
BEGIN
    DECLARE v_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT TRIM(COALESCE(p_location,''));
    DECLARE v_box BOOL DEFAULT p_north IS NOT NULL;
    IF (p_north IS NULL)<>(p_south IS NULL) OR (p_north IS NULL)<>(p_east IS NULL) OR (p_north IS NULL)<>(p_west IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khung bản đồ cần đủ bốn cạnh bắc, nam, đông, tây.';
    END IF;
    IF v_box AND (p_north>90 OR p_south<-90 OR p_south>=p_north OR p_east<-180 OR p_east>180 OR p_west<-180 OR p_west>180 OR p_east=p_west) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khung bản đồ không hợp lệ: vĩ độ từ -90 đến 90, kinh độ từ -180 đến 180 và cạnh nam phải thấp hơn cạnh bắc.';
    END IF;
    IF p_limit IS NULL OR p_limit<1 OR p_limit>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số kết quả trên bản đồ phải từ 1 đến 100.';
    END IF;
    IF COALESCE(p_guests,0)<0 OR COALESCE(p_min,0)<0 OR COALESCE(p_max,0)<0
        OR (COALESCE(p_min,0)>0 AND COALESCE(p_max,0)>0 AND p_min>p_max) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số khách hoặc khoảng giá không hợp lệ.';
    END IF;
    IF (p_start IS NULL)<>(p_end IS NULL) OR p_end<=p_start OR p_start<CURDATE() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.';
    END IF;
    SELECT SQL_CALC_FOUND_ROWS l.id,l.title,l.city,pt.name property_type,l.max_guests,l.base_nightly_rate nightly_price,l.currency,l.latitude,l.longitude,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL
        AND (NOT v_box OR (l.latitude BETWEEN p_south AND p_north
            AND ((p_west<p_east AND l.longitude BETWEEN p_west AND p_east) OR (p_west>p_east AND (l.longitude>=p_west OR l.longitude<=p_east)))))
        AND (v_location='' OR l.city LIKE CONCAT('%',v_location,'%') OR l.address LIKE CONCAT('%',v_location,'%') OR l.title LIKE CONCAT('%',v_location,'%'))
        AND (COALESCE(p_guests,0)=0 OR l.max_guests>=p_guests) AND (COALESCE(p_type,0)=0 OR l.property_type_id=p_type)
        AND (COALESCE(p_min,0)=0 OR l.base_nightly_rate>=p_min) AND (COALESCE(p_max,0)=0 OR l.base_nightly_rate<=p_max)
        AND (p_start IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY rating DESC,review_count DESC,l.id DESC
    LIMIT p_limit;
    SELECT FOUND_ROWS() __total;
END$$

-- One row per account and listing. Own listings and listings that are not public are skipped, not errors.
CREATE OR REPLACE PROCEDURE sp_discovery_view_record(IN p_user BIGINT UNSIGNED, IN p_listing BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_recorded INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_discovery_view; RELEASE SAVEPOINT h2h_discovery_view; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_discovery_view; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để lưu lịch sử xem.';
    END IF;
    IF EXISTS(SELECT 1 FROM listings l JOIN users h ON h.id=l.host_id
        WHERE l.id=p_listing AND l.host_id<>p_user AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL
            AND h.status='active' AND h.deleted_at IS NULL) THEN
        INSERT INTO listing_views(user_id,listing_id,view_count,first_viewed_at,last_viewed_at) VALUES(p_user,p_listing,1,NOW(6),NOW(6))
            ON DUPLICATE KEY UPDATE view_count=LEAST(view_count+1,2147483647),last_viewed_at=NOW(6);
        SET v_recorded=1;
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_discovery_view; END IF;
    SELECT v_recorded recorded;
END$$

-- Zero/blank criteria mean "not used". A search with no criteria besides the default single guest is not stored.
-- Repeating the latest identical search only refreshes its time; each account keeps its latest 20 searches.
CREATE OR REPLACE PROCEDURE sp_discovery_search_record(IN p_user BIGINT UNSIGNED, IN p_source VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_check_in DATE, IN p_check_out DATE, IN p_guests INT,
    IN p_type BIGINT UNSIGNED, IN p_min DECIMAL(12,2), IN p_max DECIMAL(12,2))
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_latest BIGINT UNSIGNED;
    DECLARE v_cutoff BIGINT UNSIGNED;
    DECLARE v_recorded INT DEFAULT 0;
    DECLARE v_location VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULLIF(LEFT(TRIM(COALESCE(p_location,'')),150),'');
    DECLARE v_guests INT DEFAULT NULLIF(COALESCE(p_guests,0),0);
    DECLARE v_type BIGINT UNSIGNED DEFAULT NULLIF(COALESCE(p_type,0),0);
    DECLARE v_min DECIMAL(12,2) DEFAULT NULLIF(COALESCE(p_min,0),0);
    DECLARE v_max DECIMAL(12,2) DEFAULT NULLIF(COALESCE(p_max,0),0);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_discovery_search; RELEASE SAVEPOINT h2h_discovery_search; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_discovery_search; END IF;
    IF p_source IS NULL OR p_source NOT IN ('home','map') OR COALESCE(p_guests,0)<0 OR COALESCE(p_guests,0)>65535
        OR COALESCE(p_min,0)<0 OR COALESCE(p_max,0)<0 OR (v_min IS NOT NULL AND v_max IS NOT NULL AND v_min>v_max)
        OR (p_check_in IS NULL)<>(p_check_out IS NULL) OR p_check_out<=p_check_in
        OR (v_type IS NOT NULL AND NOT EXISTS(SELECT 1 FROM property_types WHERE id=v_type)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Điều kiện tìm kiếm không hợp lệ để lưu lịch sử.';
    END IF;
    -- Locking the account serializes concurrent searches so trimming cannot race.
    SELECT id INTO v_lock FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để lưu lịch sử tìm kiếm.'; END IF;
    IF v_location IS NOT NULL OR p_check_in IS NOT NULL OR v_type IS NOT NULL OR v_min IS NOT NULL OR v_max IS NOT NULL OR COALESCE(v_guests,0)>1 THEN
        SET v_latest=(SELECT id FROM search_history WHERE user_id=p_user ORDER BY id DESC LIMIT 1);
        IF v_latest IS NOT NULL AND EXISTS(SELECT 1 FROM search_history WHERE id=v_latest AND location<=>v_location AND check_in<=>p_check_in
            AND check_out<=>p_check_out AND guest_count<=>v_guests AND property_type_id<=>v_type AND min_price<=>v_min AND max_price<=>v_max) THEN
            UPDATE search_history SET source=p_source,searched_at=NOW(6) WHERE id=v_latest;
        ELSE
            INSERT INTO search_history(user_id,source,location,check_in,check_out,guest_count,property_type_id,min_price,max_price,searched_at)
                VALUES(p_user,p_source,v_location,p_check_in,p_check_out,v_guests,v_type,v_min,v_max,NOW(6));
        END IF;
        SET v_cutoff=(SELECT id FROM search_history WHERE user_id=p_user ORDER BY id DESC LIMIT 1 OFFSET 19);
        IF v_cutoff IS NOT NULL THEN DELETE FROM search_history WHERE user_id=p_user AND id<v_cutoff; END IF;
        SET v_recorded=1;
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_discovery_search; END IF;
    SELECT v_recorded recorded;
END$$

-- Scores public listings the account does not own. Signals: other viewed listings in the same city, searched places,
-- shared property type, closeness to the preferred nightly price, rating/popularity; listings already viewed rank lower.
-- Without any history every signal is zero, so the order falls back to rating, review count and popularity.
CREATE OR REPLACE PROCEDURE sp_discovery_recommendations(IN p_user BIGINT UNSIGNED, IN p_limit INT)
BEGIN
    DECLARE v_history BOOL DEFAULT FALSE;
    DECLARE v_view_price DECIMAL(14,2);
    DECLARE v_search_price DECIMAL(14,2);
    DECLARE v_price DECIMAL(14,2);
    IF p_limit IS NULL OR p_limit<1 OR p_limit>24 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số gợi ý phải từ 1 đến 24.';
    END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để nhận gợi ý.';
    END IF;
    SET v_history=EXISTS(SELECT 1 FROM listing_views WHERE user_id=p_user) OR EXISTS(SELECT 1 FROM search_history WHERE user_id=p_user);
    SET v_view_price=(SELECT SUM(l.base_nightly_rate*LEAST(v.view_count,5))/SUM(LEAST(v.view_count,5))
        FROM listing_views v JOIN listings l ON l.id=v.listing_id WHERE v.user_id=p_user);
    SET v_search_price=(SELECT AVG(CASE WHEN min_price IS NOT NULL AND max_price IS NOT NULL THEN (min_price+max_price)/2 ELSE COALESCE(max_price,min_price) END)
        FROM search_history WHERE user_id=p_user AND (min_price IS NOT NULL OR max_price IS NOT NULL));
    SET v_price=CASE WHEN v_view_price IS NOT NULL AND v_search_price IS NOT NULL THEN (v_view_price+v_search_price)/2 ELSE COALESCE(v_view_price,v_search_price) END;
    SELECT c.id,c.title,c.city,c.property_type,c.max_guests,c.nightly_price,c.currency,c.image_url,c.rating,c.review_count,c.seen_count,
        v_history personalized,
        ROUND((CASE WHEN c.city_views>0 THEN 3+LEAST(c.city_views-1,4)*0.25 ELSE 0 END)
            +(CASE WHEN c.search_hits>0 THEN 2+LEAST(c.search_hits-1,4)*0.2 ELSE 0 END)
            +(CASE WHEN c.type_hits>0 THEN 1.5 ELSE 0 END)
            +1.5*c.price_fit
            +c.rating/5+LEAST(c.review_count,20)/40+LEAST(c.viewer_count,10)/20
            -(CASE WHEN c.seen_count>0 THEN 2.5 ELSE 0 END),3) score,
        CASE WHEN c.seen_count>0 THEN 'Bạn đã xem chỗ ở này gần đây'
            WHEN c.city_views>0 THEN CONCAT('Vì bạn đã xem chỗ ở tại ',c.city)
            WHEN c.search_hits>0 THEN CONCAT('Vì bạn đã tìm kiếm “',c.search_location,'”')
            WHEN c.type_hits>0 THEN CONCAT('Cùng loại “',c.property_type,'” bạn quan tâm')
            WHEN c.price_fit>=0.75 THEN 'Mức giá gần với lựa chọn của bạn'
            WHEN c.review_count>0 AND c.rating>=4 THEN 'Được khách đánh giá cao'
            WHEN c.viewer_count>0 THEN 'Nhiều người đang quan tâm'
            ELSE 'Chỗ ở nổi bật trên Home2Home' END reason
    FROM (
        SELECT l.id,l.title,l.city,pt.name property_type,l.max_guests,l.base_nightly_rate nightly_price,l.currency,
            (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
            COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
            (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count,
            (SELECT COUNT(*) FROM listing_views pv WHERE pv.listing_id=l.id) viewer_count,
            COALESCE((SELECT v.view_count FROM listing_views v WHERE v.user_id=p_user AND v.listing_id=l.id),0) seen_count,
            COALESCE((SELECT SUM(LEAST(v.view_count,5)) FROM listing_views v JOIN listings vl ON vl.id=v.listing_id
                WHERE v.user_id=p_user AND vl.id<>l.id AND vl.city=l.city),0) city_views,
            (SELECT COUNT(*) FROM search_history s WHERE s.user_id=p_user AND s.location IS NOT NULL
                AND (l.city LIKE CONCAT('%',s.location,'%') OR l.address LIKE CONCAT('%',s.location,'%') OR l.title LIKE CONCAT('%',s.location,'%'))) search_hits,
            (SELECT s.location FROM search_history s WHERE s.user_id=p_user AND s.location IS NOT NULL
                AND (l.city LIKE CONCAT('%',s.location,'%') OR l.address LIKE CONCAT('%',s.location,'%') OR l.title LIKE CONCAT('%',s.location,'%'))
                ORDER BY s.id DESC LIMIT 1) search_location,
            (SELECT COUNT(*) FROM listing_views v JOIN listings vl ON vl.id=v.listing_id WHERE v.user_id=p_user AND vl.id<>l.id AND vl.property_type_id=l.property_type_id)
                +(SELECT COUNT(*) FROM search_history s WHERE s.user_id=p_user AND s.property_type_id=l.property_type_id) type_hits,
            CASE WHEN v_price IS NULL OR v_price<=0 THEN 0 ELSE GREATEST(0,1-ABS(l.base_nightly_rate-v_price)/v_price) END price_fit
        FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
        WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
            AND l.host_id<>p_user
    ) c
    ORDER BY score DESC,c.rating DESC,c.review_count DESC,c.id DESC
    LIMIT p_limit;
END$$

-- Only the account's own history, and only listings that are still public.
CREATE OR REPLACE PROCEDURE sp_discovery_recent_views(IN p_user BIGINT UNSIGNED, IN p_limit INT)
BEGIN
    IF p_limit IS NULL OR p_limit<1 OR p_limit>20 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số chỗ ở đã xem phải từ 1 đến 20.';
    END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để xem lịch sử.';
    END IF;
    SELECT l.id,l.title,l.city,l.base_nightly_rate nightly_price,l.currency,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        v.view_count,v.last_viewed_at
    FROM listing_views v JOIN listings l ON l.id=v.listing_id JOIN users h ON h.id=l.host_id
    WHERE v.user_id=p_user AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL
    ORDER BY v.last_viewed_at DESC,l.id DESC
    LIMIT p_limit;
END$$

CREATE OR REPLACE PROCEDURE sp_discovery_history_summary(IN p_user BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để xem lịch sử.';
    END IF;
    SELECT (SELECT COUNT(*) FROM listing_views WHERE user_id=p_user) viewed_listings,
        (SELECT COALESCE(SUM(view_count),0) FROM listing_views WHERE user_id=p_user) total_views,
        (SELECT COUNT(*) FROM search_history WHERE user_id=p_user) searches,
        (SELECT MAX(last_viewed_at) FROM listing_views WHERE user_id=p_user) last_viewed_at,
        (SELECT MAX(searched_at) FROM search_history WHERE user_id=p_user) last_searched_at;
END$$

-- Clears views and searches of the calling account only; other accounts are never touched.
CREATE OR REPLACE PROCEDURE sp_discovery_history_clear(IN p_user BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_views INT DEFAULT 0;
    DECLARE v_searches INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_discovery_clear; RELEASE SAVEPOINT h2h_discovery_clear; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_discovery_clear; END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_user AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để xóa lịch sử.'; END IF;
    DELETE FROM listing_views WHERE user_id=p_user;
    SET v_views=ROW_COUNT();
    DELETE FROM search_history WHERE user_id=p_user;
    SET v_searches=ROW_COUNT();
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_discovery_clear; END IF;
    SELECT v_views views_cleared,v_searches searches_cleared;
END$$

DELIMITER ;
