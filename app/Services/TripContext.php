<?php
declare(strict_types=1);
namespace App\Services;

/** Non-authoritative journey inputs. Prices and availability always come from BookingService. */
final class TripContext
{
    public static function inputs(array $source): array
    {
        $trip=[];
        foreach (['check_in','check_out','guests','promo_code'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) { $trip[$key]=mb_substr(trim((string)$source[$key]),0,40); }
        }
        return $trip;
    }

    public static function path(int $listingId, array $trip, bool $review=false): string
    {
        $query=http_build_query(self::inputs($trip));
        return '/listings/'.$listingId.($review?'/review':'').($query?'?'.$query:'');
    }

    public static function safeReturn(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>4096 || preg_match('/[\x00-\x20\\\\]/',rawurldecode($value))) { return ''; }
        $base=rtrim((string)(parse_url(url('/'),PHP_URL_PATH)??''),'/');
        if ($base!=='' && str_starts_with($value,$base.'/')) { $value=substr($value,strlen($base)); }
        // Only guest-facing GET routes; never return to a mutation or external URL.
        return preg_match('~^/(?:\?|#|$|listings/\d+(?:/review)?(?:[?#]|$)|(?:wishlist|profile|messages|notifications|reports|map)(?:[?#]|$)|(?:bookings|payments)(?:/\d+)?(?:[?#]|$))~D',$value) ? $value : '';
    }

    public static function fingerprint(array $quote, array $listing): string
    {
        return hash('sha256',json_encode([
            number_format((float)$quote['total'],2,'.',''), (bool)$quote['instant_book'],
            $listing['cancellation_policy_id'], $listing['cancellation_description'],
            $listing['check_in_time'], $listing['check_out_time'],
            $listing['cutoff_hours']??null, $listing['refund_percent']??null,
            $listing['house_rules']??null, $listing['allows_pets']??null,
            $listing['allows_smoking']??null, $listing['allows_parties']??null,
            $listing['currency']??null, $listing['time_zone']??null,
        ],JSON_THROW_ON_ERROR));
    }
}
