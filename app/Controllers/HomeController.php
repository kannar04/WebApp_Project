<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Listing;
use App\Models\Wishlist;
use Core\Auth;
use Core\Controller;

final class HomeController extends Controller
{
    public function index(): void
    {
        [$filters,$searchError,$filterErrors]=$this->validatedFilters($_GET);
        $model=new Listing(); $perPage=12;
        // Invalid input is retained for correction, but never executed as a search.
        $page=$searchError===null?(int)($filters['page']??1):1;
        $result=$searchError===null?$model->searchPage($filters,$perPage,($page-1)*$perPage):['listings'=>[],'total'=>0];
        $pages=max(1,(int)ceil($result['total']/$perPage));
        if ($page>$pages) {
            $page=$pages; $filters['page']=$page;
            $result=$model->searchPage($filters,$perPage,($page-1)*$perPage);
        }
        $hasFilters=false;
        foreach ($filters as $name=>$value) {
            if ($name!=='page' && ($name==='amenities'?count($value)>0:trim((string)$value)!=='')) { $hasFilters=true; }
        }
        $favorites=Auth::check()?(new Wishlist())->ids((int)Auth::id()):[];
        $this->render('home',[
            'title'=>'Tìm chỗ ở ấm áp','listings'=>$result['listings'],'total'=>$result['total'],
            'types'=>$model->types(),'amenities'=>$model->allAmenities(),'filters'=>$filters,'favorites'=>$favorites,
            'searchError'=>$searchError,'filterErrors'=>$filterErrors,'hasFilters'=>$hasFilters,'page'=>$page,'pages'=>$pages
        ]);
    }

    public function search(): never
    {
        [$filters,$searchError,$filterErrors]=$this->validatedFilters($_GET);
        if ($searchError!==null) { $this->json(false,$searchError,null,$filterErrors,422); }
        $page=(int)($filters['page']??1);
        $result=(new Listing())->searchPage($filters,12,($page-1)*12);
        $this->json(true,'Đã tải kết quả.',$result+['page'=>$page,'per_page'=>12,'pages'=>(int)ceil($result['total']/12)]);
    }

    private function validatedFilters(array $input): array
    {
        $filters=[]; $errors=[];
        $fields=['location','check_in','check_out','guests','type','min_price','max_price','bedrooms','sort','page'];
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
        return [$filters,$errors?implode(' ',array_unique(array_values($errors))):null,$errors];
    }
}

