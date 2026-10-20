-- LOCAL TESTS ONLY for the articles/travel guides feature. Installed only with the testing opt-in.
DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_test_content_article_inspect(IN p_article BIGINT UNSIGNED)
BEGIN
    SELECT a.id,a.author_id,a.category,a.title,a.slug,a.status,a.published_at,a.deleted_at,a.cover_image_url,a.cover_alt,
        (SELECT COUNT(*) FROM admin_audit_logs l WHERE l.entity_type='article' AND l.entity_id=a.id) audit_count,
        (SELECT GROUP_CONCAT(l.action ORDER BY l.id SEPARATOR ',') FROM admin_audit_logs l WHERE l.entity_type='article' AND l.entity_id=a.id) audit_actions
    FROM articles a WHERE a.id=p_article;
END$$
CREATE OR REPLACE PROCEDURE sp_test_content_cleanup(IN p_marker VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    -- Removes only this run's articles: slugs content-audit-<marker>... or articles written by audit-content-<marker>-* users.
    IF p_marker IS NULL OR p_marker COLLATE utf8mb4_bin NOT REGEXP '^[a-f0-9]{16}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove content without an exact fixture marker.';
    END IF;
    SELECT a.cover_image_url FROM articles a
    WHERE (a.slug LIKE CONCAT('content-audit-',p_marker,'%')
            OR a.author_id IN (SELECT u.id FROM users u WHERE u.email LIKE CONCAT('audit-content-',p_marker,'-%@home2home.test')))
        AND a.cover_image_url LIKE '/uploads/article-%';
    DELETE FROM admin_audit_logs WHERE entity_type='article' AND entity_id IN (
        SELECT a.id FROM articles a WHERE a.slug LIKE CONCAT('content-audit-',p_marker,'%')
            OR a.author_id IN (SELECT u.id FROM users u WHERE u.email LIKE CONCAT('audit-content-',p_marker,'-%@home2home.test')));
    DELETE FROM articles WHERE slug LIKE CONCAT('content-audit-',p_marker,'%')
        OR author_id IN (SELECT u.id FROM users u WHERE u.email LIKE CONCAT('audit-content-',p_marker,'-%@home2home.test'));
END$$
DELIMITER ;
