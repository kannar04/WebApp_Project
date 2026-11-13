<?php
declare(strict_types=1);
namespace App\Services;

final class ListingCity
{
    public static function groups(): array
    {
        return require dirname(__DIR__,2).'/config/cities.php';
    }

    public static function canonical(string $value): ?string
    {
        $key=self::key($value);
        foreach (self::groups() as $cities) {
            foreach ($cities as $city) {
                if (self::key($city)===$key) { return $city; }
            }
        }
        $aliases=['hcm'=>'Hồ Chí Minh','tphcm'=>'Hồ Chí Minh','tp hcm'=>'Hồ Chí Minh',
            'ho chi minh city'=>'Hồ Chí Minh','sai gon'=>'Hồ Chí Minh','saigon'=>'Hồ Chí Minh',
            'hanoi'=>'Hà Nội','danang'=>'Đà Nẵng','dalat'=>'Đà Lạt','sapa'=>'Sa Pa'];
        return $aliases[$key]??null;
    }

    /** Legacy locations may be kept only on the listing that already owns that value. */
    public static function validate(string $value, ?string $existing=null): string
    {
        $canonical=self::canonical($value);
        if ($canonical!==null) { return $canonical; }
        if ($existing!==null && trim($existing)!=='' && trim($value)===trim($existing)) { return trim($existing); }
        throw new \DomainException('Vui lòng chọn tỉnh / thành phố trong danh sách.');
    }

    private static function key(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        foreach (['a'=>'àáạảãâầấậẩẫăằắặẳẵ','e'=>'èéẹẻẽêềếệểễ',
            'i'=>'ìíịỉĩ','o'=>'òóọỏõôồốộổỗơờớợởỡ','u'=>'ùúụủũưừứựửữ',
            'y'=>'ỳýỵỷỹ','d'=>'đ'] as $plain=>$letters) {
            $value=str_replace(mb_str_split($letters),$plain,$value);
        }
        $value=preg_replace('/[^a-z0-9]+/',' ',strtolower($value));
        $value=trim(preg_replace('/\s+/',' ',$value));
        return trim(preg_replace('/^(?:thanh pho|tinh|tp)\s+/','',$value));
    }
}
