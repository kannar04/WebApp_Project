<?php

declare(strict_types=1);

namespace Core;

class Controller
{
    public function render(string $view, array $data = [], string $layout = 'layouts/main'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = dirname(__DIR__) . '/app/Views/' . $view . '.php';
        $layoutFile = dirname(__DIR__) . '/app/Views/' . $layout . '.php';
        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new \RuntimeException('View không tồn tại: ' . $view);
        }
        // Views render before layouts, so shared view helpers must be loaded here.
        require_once dirname(__DIR__) . '/app/Views/components/ui-icons.php';
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        require $layoutFile;
    }

    public function redirect(string $path): never
    {
        header('Location: ' . url($path));
        exit;
    }

    /** Where a list form came from, filters included; anything but that same list on this site falls back to the bare list. */
    protected static function listReturnPath(string $listPath): string
    {
        $path = I18n::safeReturnPath($_POST['return_path'] ?? $listPath);
        return parse_url($path, PHP_URL_PATH) === $listPath ? $path : $listPath;
    }

    public function json(bool $success, string $message, mixed $data = null, array $errors = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Controllers pass Vietnamese source text; API clients get it in the visitor's interface language.
        $message = I18n::translate($message);
        foreach ($errors as $field => $error) {
            if (is_string($error)) { $errors[$field] = I18n::translate($error); }
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $body = json_encode(compact('success', 'message', 'data', 'errors'), $flags);
        if ($body === false) {
            // Only data JSON cannot hold reaches here (INF, NAN, too deep): a server fault, still answered in JSON.
            http_response_code(500);
            $body = json_encode(['success' => false, 'message' => I18n::translate('Home2Home đang tạm thời gặp sự cố. Vui lòng thử lại.'), 'data' => null, 'errors' => []], $flags);
        }
        echo $body;
        exit;
    }

    protected function requireAuth(): array
    {
        $user = Auth::user();
        if (!$user) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $uri = I18n::returnPath();
                if (I18n::safeReturnPath($uri) === $uri) {
                    Session::put('intended_url', $uri);
                }
            }
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                $this->json(false, 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', null, [], 401);
            }
            Session::flash('error', 'Vui lòng đăng nhập để tiếp tục.');
            $this->redirect('/login');
        }
        return $user;
    }

    protected function requireRole(string $role): array
    {
        $user = $this->requireAuth();
        if (!in_array($role, $user['roles'], true)) {
            http_response_code(403);
            $this->render('errors/403', ['title' => 'Không có quyền truy cập']);
            exit;
        }
        return $user;
    }
}

