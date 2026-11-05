<?php
declare(strict_types=1);
namespace Core;

final class Calendar
{
    /** Strict Y-m-d, the parser for dates from a request. Anything else is null, a NUL byte too: createFromFormat throws ValueError on one. */
    public static function date(string $value): ?\DateTimeImmutable
    {
        if (str_contains($value,"\0")) { return null; }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date && $date->format('Y-m-d')===$value ? $date : null;
    }

    public static function month(mixed $value): array
    {
        $month=is_string($value) ? $value : date('Y-m');
        $start=self::date($month.'-01');
        if (!$start || $start->format('Y-m')!==$month || $start->format('Y')<'1900' || $start->format('Y')>'2200') {
            throw new \DomainException('Tháng không hợp lệ.');
        }
        return ['month'=>$month,'start'=>$start->format('Y-m-d'),'end'=>$start->modify('+1 month')->format('Y-m-d'),
            'previous'=>$start->modify('-1 month')->format('Y-m'),'next'=>$start->modify('+1 month')->format('Y-m'),
            'padding'=>(int)$start->format('N')-1];
    }
}
