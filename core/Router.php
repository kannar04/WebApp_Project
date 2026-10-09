<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:array}>> */
    private array $routes = [];

    public function get(string $path, array $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, array $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, array $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[$method][] = ['pattern' => '#^' . $pattern . '/?$#', 'handler' => $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($scriptDirectory !== '/' && $scriptDirectory !== '.' && str_starts_with($path, $scriptDirectory)) {
            $path = substr($path, strlen($scriptDirectory)) ?: '/';
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }
            [$class, $action] = $route['handler'];
            if (!class_exists($class) || !method_exists($class, $action)) {
                throw new RuntimeException('Route handler không hợp lệ.');
            }
            $arguments = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            (new $class())->{$action}(...array_values($arguments));
            return;
        }

        http_response_code(404);
        (new Controller())->render('errors/404', ['title' => 'Không tìm thấy trang']);
    }
}

