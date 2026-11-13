<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';
use App\Services\ListingCity;
function cityCheck(bool $ok,string $message): void { if(!$ok) { throw new RuntimeException($message); } }
foreach (['TP.HCM'=>'Hồ Chí Minh','Ho Chi Minh'=>'Hồ Chí Minh','TP. Hồ Chí Minh'=>'Hồ Chí Minh',
    '  hÀ nỘi  '=>'Hà Nội','Da Lat'=>'Đà Lạt','Đà Nẵng'=>'Đà Nẵng','Sapa'=>'Sa Pa'] as $input=>$expected) {
    cityCheck(ListingCity::validate($input)===$expected,'Must normalize '.$input);
}
cityCheck(count(ListingCity::groups()['Tỉnh / thành phố'])===34,'Provide all 34 provinces/cities.');
foreach (['','Made-up city','<script>alert(1)</script>'] as $input) {
    try { ListingCity::validate($input); throw new RuntimeException('Invalid city accepted'); }
    catch (DomainException $expected) {}
}
cityCheck(ListingCity::validate('Legacy location','Legacy location')==='Legacy location','Keep existing location.');
try { ListingCity::validate('New typo','Legacy location'); throw new RuntimeException('New typo accepted'); }
catch (DomainException $expected) {}
$existingCity='Legacy & location'; $cityValue=$existingCity;
ob_start(); require dirname(__DIR__).'/app/Views/components/city-select.php'; $html=ob_get_clean();
cityCheck(str_contains($html,'Legacy &amp; location') && substr_count($html,' selected')===1,'Render escaped legacy selection.');
$existingCity=''; $cityValue='TP.HCM';
ob_start(); require dirname(__DIR__).'/app/Views/components/city-select.php'; $html=ob_get_clean();
cityCheck(str_contains($html,'value="Hồ Chí Minh" selected'),'Select canonical value after a failed form submission.');
cityCheck(!str_contains($html,'value="TP.HCM"'),'Do not add posted aliases as new dropdown options.');
echo "PASS: city choices, normalization, rejection, legacy compatibility and dropdown rendering.\n";
