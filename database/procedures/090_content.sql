-- Nice-to-have content: articles and travel guides (public reading + Admin management with audit).
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_article_public_list(IN p_category VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_limit INT, IN p_offset INT)
BEGIN
    IF p_category IS NULL OR p_category NOT IN ('','news','guide') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chuyên mục bài viết không hợp lệ.'; END IF;
    IF p_limit IS NULL OR p_offset IS NULL OR p_limit<1 OR p_limit>50 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS a.id,a.category,a.title,a.slug,a.excerpt,a.cover_image_url,a.cover_alt,a.published_at
    FROM articles a
    WHERE a.status='published' AND a.deleted_at IS NULL AND (p_category='' OR a.category=p_category)
    ORDER BY a.published_at DESC,a.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$

CREATE OR REPLACE PROCEDURE sp_article_public_get(IN p_slug TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    -- Drafts and soft-deleted articles are indistinguishable from missing ones.
    SELECT a.id,a.category,a.title,a.slug,a.excerpt,a.body,a.cover_image_url,a.cover_alt,a.published_at,a.updated_at,u.full_name author_name
    FROM articles a JOIN users u ON u.id=a.author_id
    WHERE a.slug=p_slug AND a.status='published' AND a.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_article_public_related(IN p_article BIGINT UNSIGNED, IN p_limit INT)
BEGIN
    DECLARE v_category VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '';
    IF p_limit IS NULL OR p_limit<1 OR p_limit>12 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số bài viết liên quan không hợp lệ.'; END IF;
    SELECT category INTO v_category FROM articles WHERE id=p_article AND status='published' AND deleted_at IS NULL;
    SELECT a.id,a.category,a.title,a.slug,a.excerpt,a.cover_image_url,a.cover_alt,a.published_at
    FROM articles a
    WHERE a.status='published' AND a.deleted_at IS NULL AND a.id<>p_article
    ORDER BY (a.category=v_category) DESC,a.published_at DESC,a.id DESC
    LIMIT p_limit;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_articles(IN p_actor BIGINT UNSIGNED, IN p_query VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_category VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_limit INT, IN p_offset INT)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    IF p_query IS NULL OR p_category IS NULL OR p_status IS NULL OR p_category NOT IN ('','news','guide') OR p_status NOT IN ('','draft','published') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ lọc bài viết không hợp lệ.';
    END IF;
    IF p_limit IS NULL OR p_offset IS NULL OR p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    -- LOCATE keeps user-entered % and _ literal instead of treating them as LIKE wildcards.
    SELECT SQL_CALC_FOUND_ROWS a.id,a.category,a.title,a.slug,a.status,a.published_at,a.created_at,a.updated_at,a.cover_image_url,u.full_name author_name
    FROM articles a JOIN users u ON u.id=a.author_id
    WHERE a.deleted_at IS NULL AND (p_category='' OR a.category=p_category) AND (p_status='' OR a.status=p_status)
        AND (TRIM(p_query)='' OR LOCATE(TRIM(p_query),a.title)>0 OR LOCATE(TRIM(p_query),a.slug)>0)
    ORDER BY a.updated_at DESC,a.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_article_find(IN p_actor BIGINT UNSIGNED, IN p_article BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT a.id,a.author_id,a.category,a.title,a.slug,a.excerpt,a.body,a.cover_image_url,a.cover_alt,a.status,a.published_at,a.created_at,a.updated_at,u.full_name author_name
    FROM articles a JOIN users u ON u.id=a.author_id
    WHERE a.id=p_article AND a.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_article_save(IN p_actor BIGINT UNSIGNED, IN p_article BIGINT UNSIGNED,
    IN p_category VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_title TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_slug TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_auto_slug TINYINT,
    IN p_excerpt TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_body MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_cover TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_cover_alt TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_remove_cover TINYINT, IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_slug VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_old_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    -- TEXT parameters/variables: over-long input must fail the length checks below instead of being silently truncated.
    DECLARE v_title TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_alt TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_suffix INT DEFAULT 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_article_save; RELEASE SAVEPOINT h2h_article_save; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_article_save; END IF;
    CALL sp_admin_assert_role(p_actor);
    SET v_title=TRIM(p_title);
    SET v_alt=NULLIF(TRIM(COALESCE(p_cover_alt,'')),'');
    IF p_category IS NULL OR BINARY p_category NOT IN ('news','guide') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chuyên mục bài viết không hợp lệ.'; END IF;
    IF v_title IS NULL OR CHAR_LENGTH(v_title)<5 OR CHAR_LENGTH(v_title)>200 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiêu đề cần từ 5 đến 200 ký tự.'; END IF;
    IF p_slug IS NULL OR CHAR_LENGTH(p_slug)<1 OR CHAR_LENGTH(p_slug)>200 OR p_slug COLLATE utf8mb4_bin NOT REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang.';
    END IF;
    IF p_excerpt IS NULL OR CHAR_LENGTH(TRIM(p_excerpt))<1 OR CHAR_LENGTH(p_excerpt)>500 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tóm tắt cần từ 1 đến 500 ký tự.'; END IF;
    IF p_body IS NULL OR CHAR_LENGTH(TRIM(p_body))<20 OR CHAR_LENGTH(p_body)>50000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Nội dung cần từ 20 đến 50000 ký tự.'; END IF;
    IF p_cover IS NOT NULL AND (p_cover NOT REGEXP '^/uploads/[A-Za-z0-9_-]+[.](jpg|png|webp)$' OR CHAR_LENGTH(p_cover)>255) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ảnh bìa không hợp lệ.';
    END IF;
    IF v_alt IS NOT NULL AND CHAR_LENGTH(v_alt)>255 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mô tả ảnh bìa tối đa 255 ký tự.'; END IF;
    IF p_status IS NOT NULL AND BINARY p_status NOT IN ('draft','published') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái bài viết không hợp lệ.'; END IF;
    IF p_article IS NOT NULL AND p_article<>0 THEN
        SELECT id,status INTO v_id,v_old_status FROM articles WHERE id=p_article AND deleted_at IS NULL FOR UPDATE;
        IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bài viết không tồn tại hoặc đã bị xóa.'; END IF;
    END IF;
    SET v_status=COALESCE(p_status,v_old_status,'draft');
    SET v_slug=p_slug;
    IF p_auto_slug=1 THEN
        -- Generated slugs get -2, -3... Soft-deleted rows keep their slug, so they also count as taken.
        WHILE EXISTS(SELECT 1 FROM articles WHERE slug=v_slug AND id<>COALESCE(v_id,0)) DO
            SET v_suffix=v_suffix+1;
            IF v_suffix>500 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể tạo đường dẫn duy nhất. Hãy nhập đường dẫn khác.'; END IF;
            SET v_slug=CONCAT(TRIM(TRAILING '-' FROM LEFT(p_slug,199-CHAR_LENGTH(v_suffix))),'-',v_suffix);
        END WHILE;
    ELSEIF EXISTS(SELECT 1 FROM articles WHERE slug=v_slug AND id<>COALESCE(v_id,0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đường dẫn đã được dùng cho bài viết khác.';
    END IF;
    IF v_id IS NULL THEN
        INSERT INTO articles(author_id,category,title,slug,excerpt,body,cover_image_url,cover_alt,status,published_at,created_at,updated_at)
            VALUES(p_actor,p_category,v_title,v_slug,TRIM(p_excerpt),TRIM(p_body),p_cover,v_alt,v_status,IF(v_status='published',NOW(6),NULL),NOW(6),NOW(6));
        SET v_id=LAST_INSERT_ID();
        CALL sp_admin_audit_add(p_actor,'create_article','article',v_id,JSON_OBJECT('title',v_title,'slug',v_slug,'category',p_category,'status',v_status));
    ELSE
        UPDATE articles SET category=p_category,title=v_title,slug=v_slug,excerpt=TRIM(p_excerpt),body=TRIM(p_body),
            cover_image_url=CASE WHEN p_remove_cover=1 THEN NULL WHEN p_cover IS NOT NULL THEN p_cover ELSE cover_image_url END,
            cover_alt=CASE WHEN p_remove_cover=1 THEN NULL ELSE v_alt END,
            status=v_status,published_at=IF(v_status='published',COALESCE(published_at,NOW(6)),published_at),updated_at=NOW(6)
        WHERE id=v_id;
        CALL sp_admin_audit_add(p_actor,'update_article','article',v_id,JSON_OBJECT('title',v_title,'slug',v_slug,'category',p_category,
            'status_before',v_old_status,'status',v_status,'cover_changed',(p_remove_cover=1 OR p_cover IS NOT NULL)));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_article_save; END IF;
    SELECT v_id id,v_slug slug,v_status status;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_article_set_status(IN p_actor BIGINT UNSIGNED, IN p_article BIGINT UNSIGNED,
    IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_old_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_article_status; RELEASE SAVEPOINT h2h_article_status; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_article_status; END IF;
    CALL sp_admin_assert_role(p_actor);
    IF p_status IS NULL OR BINARY p_status NOT IN ('draft','published') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái bài viết không hợp lệ.'; END IF;
    SELECT id,status INTO v_id,v_old_status FROM articles WHERE id=p_article AND deleted_at IS NULL FOR UPDATE;
    IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bài viết không tồn tại hoặc đã bị xóa.'; END IF;
    -- First publication time is kept on unpublish so a later republish does not reorder history.
    UPDATE articles SET status=p_status,published_at=IF(p_status='published',COALESCE(published_at,NOW(6)),published_at),updated_at=NOW(6) WHERE id=v_id;
    CALL sp_admin_audit_add(p_actor,IF(p_status='published','publish_article','unpublish_article'),'article',v_id,JSON_OBJECT('from',v_old_status,'to',p_status));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_article_status; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_article_delete(IN p_actor BIGINT UNSIGNED, IN p_article BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_slug VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_article_delete; RELEASE SAVEPOINT h2h_article_delete; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_article_delete; END IF;
    CALL sp_admin_assert_role(p_actor);
    SELECT id,slug,title INTO v_id,v_slug,v_title FROM articles WHERE id=p_article AND deleted_at IS NULL FOR UPDATE;
    IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bài viết không tồn tại hoặc đã bị xóa.'; END IF;
    -- Soft delete: the row and its slug are retained for audit history.
    UPDATE articles SET deleted_at=NOW(6),updated_at=NOW(6) WHERE id=v_id;
    CALL sp_admin_audit_add(p_actor,'delete_article','article',v_id,JSON_OBJECT('title',v_title,'slug',v_slug));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_article_delete; END IF;
END$$

DELIMITER ;
