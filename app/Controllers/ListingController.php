<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Listing;
use App\Services\BookingService;
use Core\Controller;
final class ListingController extends Controller
{
    public function show(string $id): void
    {
        $model=new Listing(); $listing=$model->findPublic((int)$id);
        if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
        try { $calendar=\Core\Calendar::month($_GET['month']??date('Y-m')); }
        catch (\DomainException $exception) { \Core\Session::flash('error',$exception->getMessage()); $calendar=\Core\Calendar::month(date('Y-m')); }
        $this->render('listings/show',['title'=>$listing['title'],'listing'=>$listing,'calendar'=>$calendar,
            'calendarDays'=>$model->calendar((int)$id,0,$calendar['start'],$calendar['end']),'calendarPath'=>'/listings/'.$id,'preview'=>false]);
    }
    public function quote(string $id): never {try{$quote=(new BookingService())->quote((int)$id,(is_string($_GET['check_in']??null)?$_GET['check_in']:''),(is_string($_GET['check_out']??null)?$_GET['check_out']:''),(int)filter_var($_GET['guests']??null,FILTER_VALIDATE_INT));$this->json(true,'Giá đã được tính lại trên server.',$quote);}catch(\DomainException $e){$this->json(false,$e->getMessage(),null,[],422);}}
}

