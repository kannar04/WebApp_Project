<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Report;
use App\Models\Listing;
use App\Models\Booking;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class ReportController extends Controller
{
    public function index(): void
    {
        $user=$this->requireAuth(); $listingId=filter_var($_GET['listing']??null,FILTER_VALIDATE_INT)?:null;
        $bookingId=filter_var($_GET['booking']??null,FILTER_VALIDATE_INT)?:null;
        $targetTitle=null;
        if ($bookingId) {
            $booking=(new Booking())->findForUser($bookingId,(int)$user['id']);
            if (!$booking || (int)$booking['guest_id']!==(int)$user['id']) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy booking']); return; }
            $listingId=(int)$booking['listing_id']; $targetTitle='Booking #'.$bookingId.' — '.$booking['title'];
        } elseif ($listingId) {
            $listing=(new Listing())->findPublic($listingId,false);
            if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
            $targetTitle=$listing['title'];
        }
        $old=Session::pull('_report_old', []);
        if (($old['listing_id']??null)!==$listingId || ($old['booking_id']??null)!==$bookingId) { $old=[]; }
        $this->render('reports/index',['title'=>'Báo cáo và khiếu nại','reports'=>(new Report())->forUser((int)$user['id']),
            'listingId'=>$listingId,'bookingId'=>$bookingId,'targetTitle'=>$targetTitle,'adminMode'=>false,'old'=>$old]);
    }
    public function create(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        $listingId=filter_var($_POST['listing_id']??null,FILTER_VALIDATE_INT)?:null;
        $bookingId=filter_var($_POST['booking_id']??null,FILTER_VALIDATE_INT)?:null;
        try {
            $category=$_POST['category']??''; $description=$_POST['description']??'';
            if (!is_string($category) || !is_string($description) || mb_strlen($description)>5000) { throw new \DomainException('Thông tin báo cáo không hợp lệ.'); }
            (new Report())->create((int)$user['id'],$listingId,$bookingId,$category,$description); Session::flash('success','Admin đã nhận báo cáo. Bạn có thể theo dõi kết quả ở đây.');
        } catch (\DomainException $exception) {
            Session::put('_report_old', ['listing_id'=>$listingId,'booking_id'=>$bookingId,
                'category'=>is_string($category)?$category:'','description'=>is_string($description)?$description:'']);
            Session::flash('error',$exception->getMessage());
            $this->redirect('/reports?'.http_build_query(['listing'=>$listingId,'booking'=>$bookingId]));
        }
        Session::pull('_report_old');
        $this->redirect('/reports');
    }
    public function admin(): void
    {
        $user=$this->requireRole('admin');
        $this->render('reports/index',['title'=>'Xử lý báo cáo','reports'=>(new Report())->forAdmin((int)$user['id']),'adminMode'=>true,'targetTitle'=>null]);
    }
    public function resolve(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('admin');
        try {
            $status=$_POST['status']??''; $resolution=$_POST['resolution']??'';
            if (!is_string($status) || !is_string($resolution) || mb_strlen($resolution)>5000) { throw new \DomainException('Thông tin xử lý không hợp lệ.'); }
            (new Report())->resolve((int)$id,(int)$user['id'],$status,$resolution); Session::flash('success','Đã cập nhật xử lý báo cáo.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/admin/reports');
    }
}
