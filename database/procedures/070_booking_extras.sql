-- Nice-to-have booking extras: flexible pricing, promo codes, Instant Book, payment status.
-- MariaDB 10.4. Additive routines only; sp_booking_quote/sp_booking_create in 020 call the pricing helpers below.
-- Money is VND: discounts are rounded to whole dong so stored nights, breakdown and totals always agree.
DELIMITER $$

-- Per-night price precedence: holiday price (set and night is a holiday) > weekend price (Fri/Sat night) > base rate.
CREATE OR REPLACE PROCEDURE sp_booking_extras_night_rate(IN p_day DATE, IN p_base DECIMAL(12,2), IN p_weekend DECIMAL(12,2), IN p_holiday DECIMAL(12,2),
    OUT o_rate DECIMAL(12,2), OUT o_kind VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF p_holiday IS NOT NULL AND EXISTS(SELECT 1 FROM public_holidays WHERE holiday_date=p_day) THEN
        SET o_rate=p_holiday; SET o_kind='holiday';
    ELSEIF p_weekend IS NOT NULL AND DAYOFWEEK(p_day) IN (6,7) THEN
        SET o_rate=p_weekend; SET o_kind='weekend';
    ELSE
        SET o_rate=p_base; SET o_kind='base';
    END IF;
END$$

-- Shared by quote (p_strict=0: invalid promo becomes a message) and create (p_strict=1: locking reads, invalid promo signals).
CREATE OR REPLACE PROCEDURE sp_booking_extras_price(IN p_listing BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE,
    IN p_promo VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_strict BOOL,
    OUT o_nights INT, OUT o_base DECIMAL(12,2), OUT o_weekend_nights INT, OUT o_weekend_rate DECIMAL(12,2),
    OUT o_holiday_nights INT, OUT o_holiday_rate DECIMAL(12,2), OUT o_subtotal DECIMAL(12,2),
    OUT o_length_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_length_pct DECIMAL(5,2), OUT o_length_amount DECIMAL(12,2),
    OUT o_promo_id BIGINT UNSIGNED, OUT o_promo_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_promo_amount DECIMAL(12,2),
    OUT o_promo_message VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_fee DECIMAL(12,2), OUT o_total DECIMAL(12,2), OUT o_instant BOOL)
BEGIN
    DECLARE v_day DATE;
    DECLARE v_rate DECIMAL(12,2);
    DECLARE v_kind VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_weekly DECIMAL(5,2);
    DECLARE v_monthly DECIMAL(5,2);
    DECLARE v_after DECIMAL(12,2);
    DECLARE v_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_pid BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_value DECIMAL(12,2);
    DECLARE v_max DECIMAL(12,2);
    DECLARE v_min_nights INT;
    DECLARE v_min_subtotal DECIMAL(12,2);
    DECLARE v_from DATE;
    DECLARE v_to DATE;
    DECLARE v_limit INT;
    DECLARE v_active BOOL;
    DECLARE v_used INT DEFAULT 0;
    DECLARE v_today DATE DEFAULT DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    DECLARE v_message VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SET o_weekend_rate=NULL; SET o_holiday_rate=NULL; SET v_weekly=NULL; SET v_monthly=NULL; SET o_instant=FALSE;
    IF p_strict THEN
        SELECT base_nightly_rate,fee_amount INTO o_base,o_fee FROM listings WHERE id=p_listing FOR UPDATE;
        SELECT weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book=1
            INTO o_weekend_rate,o_holiday_rate,v_weekly,v_monthly,o_instant FROM listing_booking_settings WHERE listing_id=p_listing LOCK IN SHARE MODE;
    ELSE
        SELECT base_nightly_rate,fee_amount INTO o_base,o_fee FROM listings WHERE id=p_listing;
        SELECT weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book=1
            INTO o_weekend_rate,o_holiday_rate,v_weekly,v_monthly,o_instant FROM listing_booking_settings WHERE listing_id=p_listing;
    END IF;
    IF o_base IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng.'; END IF;
    SET o_instant=COALESCE(o_instant,FALSE);
    SET o_nights=DATEDIFF(p_end,p_start); SET o_subtotal=0; SET o_weekend_nights=0; SET o_holiday_nights=0; SET v_day=p_start;
    WHILE v_day<p_end DO
        CALL sp_booking_extras_night_rate(v_day,o_base,o_weekend_rate,o_holiday_rate,v_rate,v_kind);
        SET o_subtotal=o_subtotal+v_rate;
        IF v_kind='weekend' THEN SET o_weekend_nights=o_weekend_nights+1; ELSEIF v_kind='holiday' THEN SET o_holiday_nights=o_holiday_nights+1; END IF;
        SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
    END WHILE;
    -- Monthly replaces weekly; the two are never stacked.
    IF o_nights>=28 AND COALESCE(v_monthly,0)>0 THEN SET o_length_type='monthly'; SET o_length_pct=v_monthly;
    ELSEIF o_nights>=7 AND COALESCE(v_weekly,0)>0 THEN SET o_length_type='weekly'; SET o_length_pct=v_weekly;
    ELSE SET o_length_type='none'; SET o_length_pct=0;
    END IF;
    SET o_length_amount=ROUND(o_subtotal*o_length_pct/100,0);
    SET v_after=o_subtotal-o_length_amount;
    SET o_promo_id=NULL; SET o_promo_code=NULL; SET o_promo_amount=0; SET o_promo_message=NULL;
    SET v_code=UPPER(TRIM(COALESCE(p_promo,'')));
    IF v_code<>'' THEN
        IF NOT (CAST(v_code AS BINARY) REGEXP '^[A-Z0-9]{4,20}$') THEN
            SET o_promo_message='Mã khuyến mãi không hợp lệ (4–20 chữ cái hoặc số).';
        ELSE
            IF p_strict THEN
                SELECT id,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active=1
                    INTO v_pid,v_type,v_value,v_max,v_min_nights,v_min_subtotal,v_from,v_to,v_limit,v_active FROM promotions WHERE code=v_code FOR UPDATE;
                IF v_pid IS NOT NULL THEN
                    -- Locking read: a caller's stale REPEATABLE READ snapshot must not hide recent uses.
                    SELECT COUNT(*) INTO v_used FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id
                        WHERE bp.promotion_id=v_pid AND b.status IN ('pending','confirmed','completed') LOCK IN SHARE MODE;
                END IF;
            ELSE
                SELECT id,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active=1
                    INTO v_pid,v_type,v_value,v_max,v_min_nights,v_min_subtotal,v_from,v_to,v_limit,v_active FROM promotions WHERE code=v_code;
                IF v_pid IS NOT NULL THEN
                    SELECT COUNT(*) INTO v_used FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id
                        WHERE bp.promotion_id=v_pid AND b.status IN ('pending','confirmed','completed');
                END IF;
            END IF;
            IF v_pid IS NULL THEN SET o_promo_message='Mã khuyến mãi không tồn tại.';
            ELSEIF NOT v_active THEN SET o_promo_message='Mã khuyến mãi đã ngừng áp dụng.';
            ELSEIF v_today<v_from THEN SET o_promo_message=CONCAT('Mã khuyến mãi chưa đến ngày áp dụng (từ ',DATE_FORMAT(v_from,'%d/%m/%Y'),').');
            ELSEIF v_today>v_to THEN SET o_promo_message='Mã khuyến mãi đã hết hạn.';
            ELSEIF v_limit IS NOT NULL AND v_used>=v_limit THEN SET o_promo_message='Mã khuyến mãi đã hết lượt sử dụng.';
            ELSEIF v_min_nights IS NOT NULL AND o_nights<v_min_nights THEN
                SET o_promo_message=CONCAT('Không đủ điều kiện: mã chỉ áp dụng cho kỳ ở từ ',v_min_nights,' đêm.');
            ELSEIF v_min_subtotal IS NOT NULL AND v_after<v_min_subtotal THEN
                SET o_promo_message=CONCAT('Không đủ điều kiện: tiền phòng tối thiểu ',REPLACE(FORMAT(v_min_subtotal,0),',','.'),'đ.');
            ELSE
                SET o_promo_id=v_pid; SET o_promo_code=v_code;
                IF v_type='percent' THEN
                    SET o_promo_amount=ROUND(v_after*v_value/100,0);
                    IF v_max IS NOT NULL THEN SET o_promo_amount=LEAST(o_promo_amount,v_max); END IF;
                ELSE
                    SET o_promo_amount=v_value;
                END IF;
                -- Discount applies to the nights subtotal after the stay-length discount and never goes below zero.
                SET o_promo_amount=LEAST(o_promo_amount,v_after);
            END IF;
        END IF;
        IF p_strict AND o_promo_message IS NOT NULL THEN
            SET v_message=o_promo_message;
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
        END IF;
    END IF;
    SET o_total=v_after-o_promo_amount+o_fee;
END$$

-- Public rule summary for the listing detail page (no private data).
CREATE OR REPLACE PROCEDURE sp_listing_booking_rules(IN p_listing BIGINT UNSIGNED)
BEGIN
    SELECT s.listing_id,s.weekend_nightly_rate,s.holiday_nightly_rate,s.weekly_discount_pct,s.monthly_discount_pct,s.instant_book
    FROM listing_booking_settings s JOIN listings l ON l.id=s.listing_id WHERE s.listing_id=p_listing AND l.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_public_holidays_upcoming(IN p_limit INT)
BEGIN
    IF p_limit IS NULL OR p_limit<1 OR p_limit>50 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số ngày lễ cần hiển thị không hợp lệ.'; END IF;
    SELECT holiday_date,name,is_demo FROM public_holidays WHERE holiday_date>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) ORDER BY holiday_date LIMIT p_limit;
END$$

CREATE OR REPLACE PROCEDURE sp_host_booking_settings_get(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_host(p_listing,p_actor);
    SELECT l.id listing_id,l.title,l.city,l.base_nightly_rate,l.fee_amount,l.currency,l.moderation_status,l.is_visible,
        s.weekend_nightly_rate,s.holiday_nightly_rate,s.weekly_discount_pct,s.monthly_discount_pct,COALESCE(s.instant_book,0) instant_book,s.updated_at settings_updated_at
    FROM listings l LEFT JOIN listing_booking_settings s ON s.listing_id=l.id WHERE l.id=p_listing;
END$$

-- Saving booking rules never touches moderation: prices/rules are not part of the moderated listing content.
CREATE OR REPLACE PROCEDURE sp_host_booking_settings_save(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_weekend DECIMAL(12,2), IN p_holiday DECIMAL(12,2), IN p_weekly DECIMAL(5,2), IN p_monthly DECIMAL(5,2), IN p_instant BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_booking_settings_save; RELEASE SAVEPOINT h2h_booking_settings_save; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_booking_settings_save; END IF;
    -- Same first lock as sp_booking_create, so a booking never prices with half-saved rules.
    SELECT id INTO v_lock FROM listings WHERE id=p_listing FOR UPDATE;
    CALL sp_quality_assert_host(p_listing,p_actor);
    -- A 0 rate would make those nights free; leave the field empty to keep the base price.
    IF (p_weekend IS NOT NULL AND (p_weekend<=0 OR p_weekend>9999999999.99)) OR (p_holiday IS NOT NULL AND (p_holiday<=0 OR p_holiday>9999999999.99)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá cuối tuần/ngày lễ phải lớn hơn 0.';
    END IF;
    IF (p_weekly IS NOT NULL AND (p_weekly<0 OR p_weekly>90)) OR (p_monthly IS NOT NULL AND (p_monthly<0 OR p_monthly>90)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phần trăm giảm giá phải từ 0 đến 90.';
    END IF;
    IF p_instant IS NULL OR p_instant NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tùy chọn Đặt ngay không hợp lệ.'; END IF;
    INSERT INTO listing_booking_settings(listing_id,weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book,created_at,updated_at)
        VALUES(p_listing,p_weekend,p_holiday,p_weekly,p_monthly,p_instant,NOW(6),NOW(6))
        ON DUPLICATE KEY UPDATE weekend_nightly_rate=VALUES(weekend_nightly_rate),holiday_nightly_rate=VALUES(holiday_nightly_rate),
            weekly_discount_pct=VALUES(weekly_discount_pct),monthly_discount_pct=VALUES(monthly_discount_pct),instant_book=VALUES(instant_book),updated_at=NOW(6);
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_settings_save; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_holidays(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT h.id,h.holiday_date,h.name,h.is_demo,h.created_at,h.updated_at,u.full_name created_by_name
    FROM public_holidays h LEFT JOIN users u ON u.id=h.created_by ORDER BY h.holiday_date,h.id;
END$$

-- Saving an existing date renames it and confirms it (clears the demo flag).
CREATE OR REPLACE PROCEDURE sp_admin_holiday_save(IN p_actor BIGINT UNSIGNED, IN p_date DATE, IN p_name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_holiday_save; RELEASE SAVEPOINT h2h_holiday_save; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_holiday_save; END IF;
    CALL sp_admin_assert_role(p_actor);
    SET v_name=TRIM(COALESCE(p_name,''));
    IF p_date IS NULL OR v_name='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập ngày và tên ngày lễ.'; END IF;
    SELECT id INTO v_id FROM public_holidays WHERE holiday_date=p_date FOR UPDATE;
    IF v_id IS NULL THEN
        INSERT INTO public_holidays(holiday_date,name,is_demo,created_by,created_at,updated_at) VALUES(p_date,v_name,0,p_actor,NOW(6),NOW(6));
        SET v_id=LAST_INSERT_ID();
    ELSE
        UPDATE public_holidays SET name=v_name,is_demo=0,created_by=p_actor,updated_at=NOW(6) WHERE id=v_id;
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'save_holiday','holiday',v_id,JSON_OBJECT('date',CAST(p_date AS CHAR),'name',v_name),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_holiday_save; END IF;
    SELECT v_id id;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_holiday_delete(IN p_actor BIGINT UNSIGNED, IN p_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_date DATE;
    DECLARE v_name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_holiday_delete; RELEASE SAVEPOINT h2h_holiday_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_holiday_delete; END IF;
    CALL sp_admin_assert_role(p_actor);
    SELECT holiday_date,name INTO v_date,v_name FROM public_holidays WHERE id=p_id FOR UPDATE;
    IF v_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày lễ không tồn tại.'; END IF;
    -- Existing bookings keep their stored night prices; only future quotes change.
    DELETE FROM public_holidays WHERE id=p_id;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'delete_holiday','holiday',p_id,JSON_OBJECT('date',CAST(v_date AS CHAR),'name',v_name),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_holiday_delete; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_promotions(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT p.id,p.code,p.description,p.discount_type,p.discount_value,p.max_discount_amount,p.min_nights,p.min_subtotal,p.valid_from,p.valid_to,
        p.usage_limit,p.is_active,p.created_at,p.updated_at,
        (SELECT COUNT(*) FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id WHERE bp.promotion_id=p.id AND b.status IN ('pending','confirmed','completed')) used_count,
        (SELECT COUNT(*) FROM booking_price_breakdowns bp WHERE bp.promotion_id=p.id) booking_count
    FROM promotions p ORDER BY p.is_active DESC,p.valid_to DESC,p.id DESC;
END$$

-- p_id=0 creates. A code that already has bookings cannot be renamed; deactivate it instead.
CREATE OR REPLACE PROCEDURE sp_admin_promotion_save(IN p_actor BIGINT UNSIGNED, IN p_id BIGINT UNSIGNED,
    IN p_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_value DECIMAL(12,2), IN p_max DECIMAL(12,2),
    IN p_min_nights INT, IN p_min_subtotal DECIMAL(12,2), IN p_from DATE, IN p_to DATE, IN p_limit INT, IN p_active BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_old_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_promotion_save; RELEASE SAVEPOINT h2h_promotion_save; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_promotion_save; END IF;
    CALL sp_admin_assert_role(p_actor);
    SET v_code=UPPER(TRIM(COALESCE(p_code,''))); SET v_description=TRIM(COALESCE(p_description,''));
    IF NOT (CAST(v_code AS BINARY) REGEXP '^[A-Z0-9]{4,20}$') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi phải gồm 4–20 chữ cái A–Z hoặc số.'; END IF;
    IF v_description='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập mô tả khuyến mãi.'; END IF;
    IF p_type IS NULL OR p_type NOT IN ('percent','fixed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Loại giảm giá không hợp lệ.'; END IF;
    IF p_value IS NULL OR p_value<=0 OR (p_type='percent' AND p_value>100) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá trị giảm không hợp lệ (phần trăm từ 1 đến 100).'; END IF;
    IF (p_max IS NOT NULL AND p_max<=0) OR (p_min_nights IS NOT NULL AND (p_min_nights<1 OR p_min_nights>365))
        OR (p_min_subtotal IS NOT NULL AND p_min_subtotal<0) OR (p_limit IS NOT NULL AND p_limit<1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Điều kiện áp dụng khuyến mãi không hợp lệ.';
    END IF;
    IF p_from IS NULL OR p_to IS NULL OR p_to<p_from THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'; END IF;
    IF p_active IS NULL OR p_active NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái khuyến mãi không hợp lệ.'; END IF;
    IF p_type='fixed' THEN SET p_max=NULL; END IF;
    IF p_id IS NULL OR p_id=0 THEN
        IF EXISTS(SELECT 1 FROM promotions WHERE code=v_code) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi đã tồn tại.'; END IF;
        INSERT INTO promotions(code,description,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active,created_by,created_at,updated_at)
            VALUES(v_code,v_description,p_type,p_value,p_max,p_min_nights,p_min_subtotal,p_from,p_to,p_limit,p_active,p_actor,NOW(6),NOW(6));
        SET v_id=LAST_INSERT_ID();
    ELSE
        SELECT id,code INTO v_id,v_old_code FROM promotions WHERE id=p_id FOR UPDATE;
        IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khuyến mãi không tồn tại.'; END IF;
        IF v_old_code<>v_code THEN
            IF EXISTS(SELECT 1 FROM booking_price_breakdowns WHERE promotion_id=v_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể đổi mã đã được dùng cho booking.'; END IF;
            IF EXISTS(SELECT 1 FROM promotions WHERE code=v_code AND id<>v_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi đã tồn tại.'; END IF;
        END IF;
        UPDATE promotions SET code=v_code,description=v_description,discount_type=p_type,discount_value=p_value,max_discount_amount=p_max,min_nights=p_min_nights,
            min_subtotal=p_min_subtotal,valid_from=p_from,valid_to=p_to,usage_limit=p_limit,is_active=p_active,updated_at=NOW(6) WHERE id=v_id;
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'save_promotion','promotion',v_id,JSON_OBJECT('code',v_code,'type',p_type,'value',p_value,'active',p_active),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_promotion_save; END IF;
    SELECT v_id id;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_promotion_toggle(IN p_actor BIGINT UNSIGNED, IN p_id BIGINT UNSIGNED, IN p_active BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_promotion_toggle; RELEASE SAVEPOINT h2h_promotion_toggle; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_promotion_toggle; END IF;
    CALL sp_admin_assert_role(p_actor);
    IF p_active IS NULL OR p_active NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái khuyến mãi không hợp lệ.'; END IF;
    SELECT id INTO v_id FROM promotions WHERE id=p_id FOR UPDATE;
    IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khuyến mãi không tồn tại.'; END IF;
    UPDATE promotions SET is_active=p_active,updated_at=NOW(6) WHERE id=v_id;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'toggle_promotion','promotion',v_id,JSON_OBJECT('active',p_active),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_promotion_toggle; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_promotion_delete(IN p_actor BIGINT UNSIGNED, IN p_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_promotion_delete; RELEASE SAVEPOINT h2h_promotion_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_promotion_delete; END IF;
    CALL sp_admin_assert_role(p_actor);
    -- Booking creation locks the same promotion row before recording a use.
    SELECT code INTO v_code FROM promotions WHERE id=p_id FOR UPDATE;
    IF v_code IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khuyến mãi không tồn tại.'; END IF;
    IF EXISTS(SELECT 1 FROM booking_price_breakdowns WHERE promotion_id=p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã đã được dùng cho booking nên không thể xóa; hãy tắt mã.';
    END IF;
    DELETE FROM promotions WHERE id=p_id;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'delete_promotion','promotion',p_id,JSON_OBJECT('code',v_code),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_promotion_delete; END IF;
END$$

-- Price breakdown and payment state for one booking; bookings created before this feature have no rows and show as pay-at-check-in.
CREATE OR REPLACE PROCEDURE sp_booking_extras_detail(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_booking(p_booking,p_actor);
    SELECT b.id booking_id,b.status booking_status,b.check_in,b.total_amount,b.fee_amount,(bp.booking_id IS NOT NULL) has_breakdown,
        bp.nights_subtotal,bp.length_discount_type,bp.length_discount_pct,bp.length_discount_amount,bp.promotion_code,
        COALESCE(bp.promotion_discount_amount,0) promotion_discount_amount,COALESCE(bp.is_instant_book,0) is_instant_book,
        COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
        CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
        pay.paid_at,pay.refunded_at,pay.note payment_note,payer.full_name paid_by_name,refunder.full_name refunded_by_name
    FROM bookings b LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
        LEFT JOIN users payer ON payer.id=pay.paid_by LEFT JOIN users refunder ON refunder.id=pay.refunded_by
    WHERE b.id=p_booking;
END$$

-- Payment states for a Guest's own bookings ('guest') or a Host's listing bookings ('host').
CREATE OR REPLACE PROCEDURE sp_booking_payment_list(IN p_actor BIGINT UNSIGNED, IN p_scope VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF p_scope IS NULL OR p_scope NOT IN ('guest','host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phạm vi thanh toán không hợp lệ.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
        OR (p_scope='host' AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền xem thanh toán.';
    END IF;
    SELECT b.id booking_id,COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
        CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
        COALESCE(bp.is_instant_book,0) is_instant_book,COALESCE(bp.promotion_discount_amount,0)+COALESCE(bp.length_discount_amount,0) discount_amount
    FROM bookings b JOIN listings l ON l.id=b.listing_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id
    WHERE (p_scope='guest' AND b.guest_id=p_actor) OR (p_scope='host' AND l.host_id=p_actor)
    ORDER BY b.id DESC;
END$$

-- Host of the listing (p_admin=0) or Admin (p_admin=1) records cash collected at/after check-in.
CREATE OR REPLACE PROCEDURE sp_booking_payment_mark_paid(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_admin BOOL,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_due DECIMAL(12,2);
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_paid; RELEASE SAVEPOINT h2h_payment_paid; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_paid; END IF;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id INTO v_host FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status,check_in,total_amount INTO v_guest,v_status,v_start,v_total FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại.'; END IF;
    IF p_admin IS NULL OR p_admin NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyền cập nhật không hợp lệ.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL) OR
        (p_admin=1 AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin')) OR
        (p_admin=0 AND (v_host<>p_actor OR NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không có quyền cập nhật thanh toán của booking này.';
    END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán trước đó.'; END IF;
    IF v_status NOT IN ('confirmed','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ ghi nhận thanh toán cho booking đã xác nhận hoặc đã hoàn thành.';
    END IF;
    IF v_start>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ ghi nhận đã thu tiền từ ngày nhận phòng trở đi.'; END IF;
    IF v_payment IS NULL THEN
        -- Bookings created before payment tracking get their record lazily.
        SET v_due=v_total;
        INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
            VALUES(p_booking,'pay_at_checkin',v_due,'paid',NOW(6),p_actor,NULL,NULL,v_note,NOW(6),NOW(6));
    ELSE
        UPDATE booking_payments SET status='paid',paid_at=NOW(6),paid_by=p_actor,note=v_note,updated_at=NOW(6) WHERE booking_id=p_booking;
    END IF;
    INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at) VALUES(p_booking,p_actor,'unpaid','paid',v_note,NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app','payment_paid','Đã ghi nhận thanh toán',
        CONCAT('Booking #',p_booking,' đã được ghi nhận thanh toán ',REPLACE(FORMAT(v_due,0),',','.'),'đ khi nhận phòng.'),'sent',1,NOW(6));
    IF p_admin THEN
        INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
            VALUES(p_actor,'mark_payment_paid','booking',p_booking,JSON_OBJECT('amount',v_due,'note',v_note),NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_paid; END IF;
END$$

-- Admin only: a collected payment is marked refunded with a mandatory note.
CREATE OR REPLACE PROCEDURE sp_booking_payment_refund(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_due DECIMAL(12,2);
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_refund; RELEASE SAVEPOINT h2h_payment_refund; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_refund; END IF;
    CALL sp_admin_assert_role(p_actor);
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id INTO v_host FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id INTO v_guest FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại.'; END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    IF v_note IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng ghi chú lý do hoàn tiền.'; END IF;
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    IF v_payment IS NULL OR v_payment<>'paid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ hoàn tiền cho booking đã ghi nhận thanh toán.'; END IF;
    UPDATE booking_payments SET status='refunded',refunded_at=NOW(6),refunded_by=p_actor,note=v_note,updated_at=NOW(6) WHERE booking_id=p_booking;
    INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at) VALUES(p_booking,p_actor,'paid','refunded',v_note,NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app','payment_refunded','Đã hoàn tiền',CONCAT('Booking #',p_booking,' đã được ghi nhận hoàn tiền ',REPLACE(FORMAT(v_due,0),',','.'),'đ.'),'sent',1,NOW(6));
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'refund_payment','booking',p_booking,JSON_OBJECT('amount',v_due,'note',v_note),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_refund; END IF;
END$$

-- p_status: '' | unpaid | paid | refunded | not_required. p_query: exact booking ID, Guest name/email or listing title.
CREATE OR REPLACE PROCEDURE sp_admin_payments(IN p_actor BIGINT UNSIGNED, IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    IF p_status IS NULL OR p_status NOT IN ('','unpaid','paid','refunded','not_required') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái thanh toán lọc không hợp lệ.'; END IF;
    SET p_query=TRIM(COALESCE(p_query,''));
    SELECT x.* FROM (
        SELECT b.id booking_id,b.listing_id,b.status booking_status,b.check_in,b.check_out,b.total_amount,b.currency,b.created_at,l.title,u.full_name guest_name,u.email guest_email,h.full_name host_name,
            COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
            CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
            (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
            pay.paid_at,pay.refunded_at,pay.note payment_note,COALESCE(bp.promotion_code,'') promotion_code
        FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id
            LEFT JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id
    ) x
    WHERE (p_status='' OR x.payment_state=p_status)
        AND (p_query='' OR CAST(x.booking_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci=p_query OR x.title LIKE CONCAT('%',p_query,'%')
            OR x.guest_name LIKE CONCAT('%',p_query,'%') OR x.guest_email LIKE CONCAT('%',p_query,'%'))
    ORDER BY x.created_at DESC,x.booking_id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_payment_totals(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT COALESCE(SUM(CASE WHEN pay.status='paid' THEN pay.amount_due END),0) collected_amount,
        COALESCE(SUM(pay.status='paid'),0) paid_count,
        COALESCE(SUM(CASE WHEN pay.status='refunded' THEN pay.amount_due END),0) refunded_amount,
        COALESCE(SUM(pay.status='refunded'),0) refunded_count,
        COALESCE(SUM(CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') THEN COALESCE(pay.amount_due,b.total_amount) END),0) outstanding_amount,
        COALESCE(SUM(COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed')),0) outstanding_count
    FROM bookings b LEFT JOIN booking_payments pay ON pay.booking_id=b.id;
END$$

DELIMITER ;
