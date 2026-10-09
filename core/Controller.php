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

    public function json(bool $success, string $message, mixed $data = null, array $errors = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(compact('success', 'message', 'data', 'errors'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function requireAuth(): array
    {
        $user = Auth::user();
        if (!$user) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                if (str_starts_with($uri, '/') && !str_starts_with($uri, '//')) {
                    Session::put('intended_url', $uri);
                }
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

