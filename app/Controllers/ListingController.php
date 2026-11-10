<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\BookingExtras;
use App\Models\Listing;
use App\Models\Wishlist;
use App\Services\BookingService;
use Core\Controller;
final class ListingController extends Controller
{
    public function show(string $id): void
    {
        $model=new Listing(); $listing=$model->findPublic((int)$id);
        if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
        \App\Services\DiscoveryService::recordView($listing);
        $old=\Core\Session::pullOld();
        if (($old['listing_id']??null)!==(int)$id) { $old=$this->bookingFromQuery(); }
        try { $calendar=\Core\Calendar::month($_GET['month']??date('Y-m')); }
        catch (\DomainException $exception) { \Core\Session::flash('error',$exception->getMessage()); $calendar=\Core\Calendar::month(date('Y-m')); }
        $trip=\App\Services\TripContext::inputs($_GET);
        [$relatedTrip,$relatedError]=HomeController::validatedFilters($trip);
        $favorites=\Core\Auth::check()?(new Wishlist())->ids((int)\Core\Auth::id()):[];
        $this->render('listings/show',['title'=>$listing['title'],'listing'=>$listing,'calendar'=>$calendar,
            'calendarDays'=>$model->calendar((int)$id,0,$calendar['start'],$calendar['end']),'calendarPath'=>'/listings/'.$id,'calendarStay'=>$this->bookingFromQuery(),'preview'=>false,'old'=>$old,'trip'=>\App\Services\TripContext::inputs($_GET),
            'isSaved'=>in_array((int)$id,$favorites,true),'saved'=>in_array((int)$id,$favorites,true),'favorites'=>$favorites,'relatedListings'=>$model->related($listing,$relatedError===null?$relatedTrip:[]),
            'trustBadges'=>(new \App\Models\Trust())->badges([(int)$listing['host_id']],\Core\Auth::id()),'reviewBadges'=>(new \App\Models\Trust())->reviewBadges((int)$id),
            'bookingRules'=>(new BookingExtras())->listingRules((int)$id),'upcomingHolidays'=>(new BookingExtras())->upcomingHolidays(6)]);
    }
    /** Dates, guests and promo code in the URL, as the sign-in link and the month arrows bring them back. Only the shape is checked here: the quote and the booking judge the values. */
    private function bookingFromQuery(): array
    {
        $values=[];
        foreach (['check_in','check_out'] as $field) {
            $date=input_text($_GET,$field);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) { $values[$field]=$date; }
        }
        $guests=filter_var($_GET['guests']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($guests!==false) { $values['guests']=$guests; }
        // Cut to the box's maxlength, as a paste would be.
        $promo=mb_substr(BookingService::cleanPromoCode(input_text($_GET,'promo_code')),0,20);
        if ($promo!=='') { $values['promo_code']=$promo; }
        return $values;
    }
    public function quote(string $id): never {try{$quote=(new BookingService())->quote((int)$id,(is_string($_GET['check_in']??null)?$_GET['check_in']:''),(is_string($_GET['check_out']??null)?$_GET['check_out']:''),(int)filter_var($_GET['guests']??null,FILTER_VALIDATE_INT),(is_string($_GET['promo_code']??null)?$_GET['promo_code']:''));$this->json(true,'Giá đã được tính lại.',$quote);}catch(\DomainException $e){$this->json(false,$e->getMessage(),null,[],422);}}
}

