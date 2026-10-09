<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Listing;
use App\Services\BookingService;
use Core\Controller;
final class ListingController extends Controller
{
    public function show(string $id): void {$listing=(new Listing())->findPublic((int)$id);if(!$listing){http_response_code(404);$this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']);return;}$this->render('listings/show',['title'=>$listing['title'],'listing'=>$listing]);}
    public function quote(string $id): never {try{$quote=(new BookingService())->quote((int)$id,(string)($_GET['check_in']??''),(string)($_GET['check_out']??''),(int)($_GET['guests']??0));$this->json(true,'Giá đã được tính lại trên server.',$quote);}catch(\Throwable $e){$this->json(false,$e->getMessage(),null,[],422);}}
}

