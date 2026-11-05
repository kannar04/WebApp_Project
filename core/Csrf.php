<?php

declare(strict_types=1);

namespace Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function ensure(): void
    {
        $limit = self::exceededPostLimit();
        if ($limit !== null) {
            // PHP's own startup warning may be waiting in the output buffer and must not come before the answer.
            while (ob_get_level() > 0) { ob_end_clean(); }
            $size = $limit >= 1048576 ? ((int) floor($limit / 1048576)) . ' MB' : ((int) ceil($limit / 1024)) . ' KB';
            self::refuse(__('Dữ liệu gửi lên quá lớn (vượt giới hạn {limit} của máy chủ) nên chưa được lưu. Vui lòng chọn tệp nhỏ hơn rồi thử lại.', ['limit' => $size]), 413);
        }
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            self::refuse('Phiên biểu mẫu đã hết hạn. Vui lòng thử lại.', 419);
        }
    }

    /** The post_max_size that made PHP drop this request's whole body and leave $_POST and $_FILES empty, otherwise null. */
    private static function exceededPostLimit(): ?int
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        return $limit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit && $_POST === [] && $_FILES === [] ? $limit : null;
    }

    /** Stops the request: JSON for scripts, otherwise back to the page it came from with the message. */
    private static function refuse(string $message, int $ajaxStatus): never
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $isAjax = str_contains($accept, 'application/json') || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code($ajaxStatus);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => I18n::translate($message), 'data' => null, 'errors' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = app_path((string) (parse_url($referer, PHP_URL_PATH) ?? '/'));
        $query = (string) (parse_url($referer, PHP_URL_QUERY) ?? '');
        $refererHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
        $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
        if ($refererHost !== $requestHost || $path === null || I18n::safeReturnPath($path) !== $path) {
            $path = '/';
            $query = '';
        }
        // Keep the query so a filtered search or ?tab=messages survives the retry.
        $back = url($path) . ($query !== '' ? '?' . $query : '');
        if (headers_sent()) {
            // display_startup_errors already printed PHP's warning (a urlencoded body is read before any buffer exists), so neither a redirect nor the session can work.
            echo '<p>' . e(I18n::translate($message)) . '</p><p><a href="' . e($back) . '">' . e(__('Quay lại')) . '</a></p>';
            exit;
        }
        Session::flash('error', $message);
        header('Location: ' . $back);
        exit;
    }
}

