<?php
declare(strict_types=1);
namespace Core;

final class Pagination
{
    /** Bounds both the rendered page size and untrusted page input. */
    public static function from(array $rows, mixed $requestedPage): array
    {
        $total = count($rows);
        $pages = max(1, (int)ceil($total / 20));
        $requested = is_scalar($requestedPage) ? filter_var($requestedPage, FILTER_VALIDATE_INT) : false;
        $page = min($pages, max(1, $requested === false ? 1 : (int)$requested));
        return ['rows'=>array_slice($rows, ($page - 1) * 20, 20), 'page'=>$page, 'pages'=>$pages, 'total'=>$total];
    }
}
