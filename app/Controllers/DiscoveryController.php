<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Discovery;
use App\Models\Listing;
use App\Services\DiscoveryService;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class DiscoveryController extends Controller
{
    public const MAP_LIMIT = 100;
    private const FILTER_FIELDS = ['location','check_in','check_out','guests','type','min_price','max_price'];
    private const BOUND_FIELDS = ['north','south','east','west'];

    public function map(): void
    {
        [$filters,$searchError,$filterErrors]=$this->filters($_GET);
        $result=['listings'=>[],'total'=>0];
        if ($searchError===null) {
            $result=(new Discovery())->mapSearch(null,$filters,self::MAP_LIMIT);
            DiscoveryService::recordSearch('map',$filters);
        }
        $this->render('discovery/map',[
            'title'=>'Bản đồ chỗ ở','filters'=>$filters,'searchError'=>$searchError,'filterErrors'=>$filterErrors,
            'types'=>(new Listing())->types(),'listings'=>self::mapItems($result['listings']),'total'=>$result['total'],
            'limit'=>self::MAP_LIMIT
        ]);
    }

    /** GET /api/listings/map — listings inside the visible map box. */
    public function search(): never
    {
        [$filters,$searchError,$errors]=$this->filters($_GET);
        [$bounds,$boundErrors]=self::bounds($_GET);
        $errors+=$boundErrors;
        if ($searchError!==null || $boundErrors) {
            $this->json(false,$searchError ?? implode(' ',array_unique(array_values($boundErrors))),null,$errors,422);
        }
        try {
            $result=(new Discovery())->mapSearch($bounds,$filters,self::MAP_LIMIT);
        } catch (\DomainException $exception) {
            $this->json(false,$exception->getMessage(),null,['map'=>$exception->getMessage()],422);
        }
        $this->json(true,'Đã tải chỗ ở trong khu vực bản đồ.',[
            'listings'=>self::mapItems($result['listings']),'total'=>$result['total'],'limit'=>self::MAP_LIMIT
        ]);
    }

    public function clearHistory(): never
    {
        Csrf::ensure();
        $user=$this->requireAuth();
        try {
            (new Discovery())->clearHistory((int)$user['id']);
            Session::flash('success','Đã xóa lịch sử xem và tìm kiếm. Gợi ý sẽ bắt đầu lại từ đầu.');
        } catch (\Throwable $exception) {
            Session::flash('error',$exception instanceof \DomainException ? $exception->getMessage() : 'Không thể xóa lịch sử. Vui lòng thử lại.');
        }
        $this->redirect('/profile#discovery-history');
    }

    /** Reuses the home search validation for the subset of filters the map supports. */
    private function filters(array $input): array
    {
        return HomeController::validatedFilters(array_intersect_key($input,array_flip(self::FILTER_FIELDS)));
    }

    /** @return array{0:?array,1:array<string,string>} normalized bounds (or null when none sent) and errors */
    public static function bounds(array $input): array
    {
        $present=[];
        foreach (self::BOUND_FIELDS as $name) {
            if (array_key_exists($name,$input) && $input[$name]!=='') { $present[$name]=$input[$name]; }
        }
        if (!$present) { return [null,[]]; }
        if (count($present)!==4) { return [null,['bounds'=>'Khung bản đồ cần đủ bốn cạnh bắc, nam, đông, tây.']]; }
        $bounds=[];
        foreach ($present as $name=>$value) {
            if (!is_string($value) || !preg_match('/^-?\d{1,3}(?:\.\d{1,12})?$/D',$value)) {
                return [null,['bounds'=>'Tọa độ khung bản đồ không hợp lệ.']];
            }
            $bounds[$name]=(float)$value;
        }
        if ($bounds['north']>90 || $bounds['south']<-90 || $bounds['south']>=$bounds['north']
            || abs($bounds['east'])>180 || abs($bounds['west'])>180 || $bounds['east']===$bounds['west']) {
            return [null,['bounds'=>'Khung bản đồ không hợp lệ: vĩ độ từ -90 đến 90, kinh độ từ -180 đến 180 và cạnh nam phải thấp hơn cạnh bắc.']];
        }
        $normalized=[];
        foreach ($bounds as $name=>$value) { $normalized[$name]=number_format($value,6,'.',''); }
        return [$normalized,[]];
    }

    /** Public JSON/view shape: only what a marker, popup and list item need. */
    public static function mapItems(array $rows): array
    {
        $items=[];
        foreach ($rows as $row) {
            $items[]=['id'=>(int)$row['id'],'title'=>(string)$row['title'],'city'=>(string)$row['city'],
                'property_type'=>(string)$row['property_type'],'max_guests'=>(int)$row['max_guests'],
                'price'=>(float)$row['nightly_price'],'price_label'=>number_format((float)$row['nightly_price'],0,',','.').'đ',
                'rating'=>round((float)$row['rating'],1),'review_count'=>(int)$row['review_count'],
                'latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude'],
                'photo'=>image_url($row['image_url']),'url'=>url('/listings/'.(int)$row['id'])];
        }
        return $items;
    }
}
