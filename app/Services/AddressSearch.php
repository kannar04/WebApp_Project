<?php
declare(strict_types=1);
namespace App\Services;

final class AddressSearch
{
    public function search(string $address): array
    {
        $config = require dirname(__DIR__,2).'/config/places.php';
        if ($config['api_key'] === '') { throw new \DomainException('Chưa cấu hình tìm địa chỉ. Bạn có thể chọn vị trí trực tiếp trên bản đồ.'); }
        $params = ['text'=>$address, 'format'=>'json', 'limit'=>5, 'lang'=>'vi', 'apiKey'=>$config['api_key']];
        $cache = sys_get_temp_dir().'/h2h-address-'.hash('sha256',json_encode($params)).'.json';
        if (is_file($cache) && filemtime($cache)>time()-86400) { return json_decode((string)file_get_contents($cache),true)??[]; }
        $request = curl_init('https://api.geoapify.com/v1/geocode/search?'.http_build_query($params));
        curl_setopt_array($request, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>10]);
        if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) { curl_setopt($request,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA); }
        $response = curl_exec($request); $status = curl_getinfo($request,CURLINFO_RESPONSE_CODE); curl_close($request);
        if (!is_string($response) || $status!==200) { throw new \DomainException('Chưa tìm được địa chỉ. Hãy thử lại hoặc chọn trên bản đồ.'); }
        $items = [];
        foreach (json_decode($response,true)['results']??[] as $result) {
            if (!isset($result['lat'],$result['lon'],$result['formatted'])) { continue; }
            $items[] = ['latitude'=>(float)$result['lat'],'longitude'=>(float)$result['lon'],'label'=>(string)$result['formatted']];
        }
        @file_put_contents($cache,json_encode($items),LOCK_EX);
        return $items;
    }
}
