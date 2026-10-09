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
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            $message = 'Phiên biểu mẫu đã hết hạn. Vui lòng thử lại.';
            $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
            $isAjax = str_contains($accept, 'application/json') || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
            if ($isAjax) {
                http_response_code(419);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $message, 'data' => null, 'errors' => []], JSON_UNESCAPED_UNICODE);
                exit;
            }
            Session::flash('error', $message);
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?? '/');
            $refererHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
            $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
            if ($refererHost !== $requestHost || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
                $path = '/';
            }
            header('Location: ' . url($path));
            exit;
        }
    }
}

