<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Listing;
use App\Models\Wishlist;
use App\Services\DiscoveryService;
use Core\Auth;
use Core\Controller;

final class HomeController extends Controller
{
    private const PUBLIC_LISTING_FIELDS=['id','title','city','property_type','max_guests','room_count','bedroom_count','bed_count','nightly_price','cleaning_fee','currency','image_url','rating','review_count'];
    private const PER_PAGE = 6;

    public function index(): void
    {
        [$filters,$searchError,$filterErrors]=$this->validatedFilters($_GET);
        $model=new Listing(); $perPage=self::PER_PAGE;
        // Invalid input is retained for correction, but never executed as a search.
        $page=$searchError===null?(int)($filters['page']??1):1;
        $result=$searchError===null?$model->searchPage($filters,$perPage,($page-1)*$perPage):['listings'=>[],'total'=>0];
        $pages=max(1,(int)ceil($result['total']/$perPage));
        if ($page>$pages) {
            $page=$pages; $filters['page']=$page;
            $result=$model->searchPage($filters,$perPage,($page-1)*$perPage);
        }
        $hasFilters=self::hasActiveFilters($filters);
        $favorites=Auth::check()?(new Wishlist())->ids((int)Auth::id()):[];
        if ($searchError===null && $hasFilters) { DiscoveryService::recordSearch('home',$filters); }
        $discovery=Auth::check() && !$hasFilters && $page===1 ? DiscoveryService::homeShelf((int)Auth::id()) : null;
        $this->render('home',[
            'title'=>'Tìm chỗ ở ấm áp','listings'=>$result['listings'],'total'=>$result['total'],
            'types'=>$model->types(),'amenities'=>$model->allAmenities(),'filters'=>$filters,'favorites'=>$favorites,
            'searchError'=>$searchError,'filterErrors'=>$filterErrors,'hasFilters'=>$hasFilters,'page'=>$page,'pages'=>$pages,
            'trustBadges'=>(new \App\Models\Trust())->badges(array_column($result['listings'],'host_id'),Auth::id()),
            'discovery'=>$discovery,'stayQuery'=>self::stayQuery($filters),'destinations'=>$model->destinations(),
            'upcoming'=>$this->upcoming()
        ]);
    }

    /** The stay from a validated search, for links to a listing, whose page quotes it straight away. One guest is the default, so an ordinary link stays plain. */
    public static function stayQuery(array $filters): string
    {
        $stay=[];
        if (($filters['check_in']??'')!=='' && ($filters['check_out']??'')!=='') { $stay=['check_in'=>$filters['check_in'],'check_out'=>$filters['check_out']]; }
        if ((int)($filters['guests']??0)>1) { $stay['guests']=(int)$filters['guests']; }
        return $stay?'?'.http_build_query($stay):'';
    }

    /** The hero always sends its default of one guest. Every listing takes at least one, so that alone is not a search. */
    private static function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $name=>$value) {
            if ($name==='page') { continue; }
            if ($name==='amenities') { $active=count($value)>0; }
            elseif ($name==='guests') { $active=(int)$value>1; }
            else { $active=trim((string)$value)!==''; }
            if ($active) { return true; }
        }
        return false;
    }

    public function search(): never
    {
        [$filters,$searchError,$filterErrors]=$this->validatedFilters($_GET);
        if ($searchError!==null) { $this->json(false,$searchError,null,$filterErrors,422); }
        $page=(int)($filters['page']??1);
        $result=(new Listing())->searchPage($filters,self::PER_PAGE,($page-1)*self::PER_PAGE);
        $trustBadges=(new \App\Models\Trust())->badges(array_column($result['listings'],'host_id'),Auth::id());
        $publicListings=[];
        foreach ($result['listings'] as $listing) { $publicListings[]=self::publicListing($listing,$trustBadges); }
        $result['listings']=$publicListings;
        $cards=[];
        if (isset($filters['page'])) {
            $listings=$publicListings; parse_str(ltrim(self::stayQuery($filters),'?'),$cardFilters);
            $favorites=Auth::check()?(new Wishlist())->ids((int)Auth::id()):[];
            ob_start(); require dirname(__DIR__).'/Views/components/listing-cards.php'; $cards['html']=ob_get_clean();
        }
        $this->json(true,'Đã tải kết quả.',$result+$cards+['page'=>$page,'per_page'=>self::PER_PAGE,'pages'=>(int)ceil($result['total']/self::PER_PAGE)]);
    }

    private function upcoming(): ?array
    {
        if (!Auth::check()) { return null; }
        $today=date('Y-m-d'); $upcoming=[];
        foreach ((new \App\Models\Booking())->forGuest((int)Auth::id()) as $booking) {
            $open=$booking['status']==='confirmed'||($booking['status']==='pending'&&$booking['check_in']>=$today);
            if ($open&&$booking['check_out']>=$today) { $upcoming[]=$booking; }
        }
        usort($upcoming,[self::class,'byCheckIn']);
        return $upcoming[0]??null;
    }

    private static function byCheckIn(array $left,array $right): int
    {
        return strcmp($left['check_in'],$right['check_in']);
    }

    /** Public API row: what a home page card shows. Moderation, audit and Host account columns stay on the server. */
    private static function publicListing(array $listing,array $trustBadges): array
    {
        $public=[];
        foreach (self::PUBLIC_LISTING_FIELDS as $field) { $public[$field]=$listing[$field]??null; }
        $public['trusted_host']=!empty($trustBadges[(int)$listing['host_id']]['is_trusted_host']);
        return $public;
    }

    public static function validatedFilters(array $input): array
    {
        $filters=[]; $errors=[];
        $fields=['location','check_in','check_out','guests','type','min_price','max_price','bedrooms','sort','page','city','north','south','east','west'];
        foreach (array_intersect_key($input,array_flip($fields)) as $name=>$value) {
            if (!is_scalar($value)) { $errors[$name]='Bộ lọc tìm kiếm không hợp lệ.'; }
            else { $filters[$name]=trim((string)$value); }
        }
        $selected=$input['amenities']??[];
        $safeSelected=[];
        if (!is_array($selected) || count($selected)>100) { $errors['amenities']='Bộ lọc tiện nghi không hợp lệ.'; }
        else {
            foreach ($selected as $id) {
                if (!is_scalar($id) || filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) {
                    $errors['amenities']='Bộ lọc tiện nghi không hợp lệ.';
                } else { $safeSelected[]=(int)$id; }
            }
        }
        $activeIds=array_map('intval',array_column((new Listing())->allAmenities(),'id'));
        if (array_diff($safeSelected,$activeIds)) { $errors['amenities']='Tiện nghi không tồn tại hoặc đã ngừng sử dụng.'; }
        $filters['amenities']=array_values(array_unique(array_intersect($safeSelected,$activeIds)));
        if (isset($filters['page']) && filter_var($filters['page'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])===false) {
            $errors['page']='Trang không hợp lệ.';
        }
        if (isset($filters['sort']) && !in_array($filters['sort'],['','newest','price_asc','price_desc','rating'],true)) { $errors['sort']='Cách sắp xếp không hợp lệ.'; }
        if (mb_strlen($filters['location']??'')>254) { $errors['location']='Địa điểm quá dài.'; }
        foreach (['guests','type','bedrooms'] as $name) {
            if (isset($filters[$name]) && $filters[$name]!=='' &&
                filter_var($filters[$name],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]])===false) {
                $errors[$name]='Số khách, loại chỗ ở hoặc số phòng không hợp lệ.';
            }
        }
        foreach (['min_price','max_price'] as $name) {
            if (isset($filters[$name]) && $filters[$name]!=='' &&
                (!is_numeric($filters[$name]) || (float)$filters[$name]<0 || (float)$filters[$name]>9999999999.99)) {
                $errors[$name]='Khoảng giá không hợp lệ.';
            }
        }
        if (!isset($errors['min_price']) && !isset($errors['max_price']) && (float)($filters['min_price']??0)>0 && (float)($filters['max_price']??0)>0
            && (float)$filters['min_price']>(float)$filters['max_price']) {
            $errors['max_price']='Giá tối đa phải lớn hơn hoặc bằng giá tối thiểu.';
        }
        if (mb_strlen($filters['city']??'')>254) { $errors['city']='Địa điểm quá dài.'; }
        [$bounds,$boundsErrors]=\App\Controllers\DiscoveryController::bounds($input);
        $errors+=$boundsErrors;
        foreach (['north','south','east','west'] as $bound) { unset($filters[$bound]); }
        if ($bounds) { $filters+=$bounds; $filters['city']=''; $filters['location']=''; }
        // A manually edited location must override a stale selected-city hidden field (also without JS).
        if (!empty($filters['city']) && ($filters['location']??'')!==$filters['city']) { unset($filters['city']); }
        $checkIn=$filters['check_in']??''; $checkOut=$filters['check_out']??'';
        if ($checkIn!=='' || $checkOut!=='') {
            $shaped=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$checkIn) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$checkOut);
            $start=$shaped?\DateTimeImmutable::createFromFormat('!Y-m-d',$checkIn):false;
            $end=$shaped?\DateTimeImmutable::createFromFormat('!Y-m-d',$checkOut):false;
            $valid=$start && $end && $start->format('Y-m-d')===$checkIn && $end->format('Y-m-d')===$checkOut &&
                $start>=new \DateTimeImmutable('today') && $end>$start;
            if (!$valid) {
                $errors['dates']='Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.';
            }
        }
        // The combined search error is translated sentence by sentence; per-field $errors stay as source text.
        return [$filters,$errors?implode(' ',array_map('__',array_unique(array_values($errors)))):null,$errors];
    }
}

