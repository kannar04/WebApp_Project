<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Booking;
use App\Models\HostRepository;
use App\Models\Listing;
use App\Services\BookingService;
use App\Services\UploadService;
use Core\Controller;
use Core\Csrf;
use Core\Session;
final class HostController extends Controller
{
    public function dashboard(): void {$u=$this->requireRole('host');$this->render('host/dashboard',['title'=>'Không gian Host','stats'=>(new HostRepository())->stats((int)$u['id']),'monthlyRevenue'=>(new HostRepository())->monthlyRevenue((int)$u['id']),'listings'=>(new Listing())->hostListings((int)$u['id']),'bookings'=>(new Booking())->forHost((int)$u['id'])]);}
    public function createForm(): void {$this->requireRole('host');$m=new Listing();$this->render('host/listing-form',['title'=>'Đăng chỗ ở','listing'=>null,'types'=>$m->types(),'policies'=>$m->policies(),'amenities'=>$m->allAmenities(),'selectedAmenities'=>[],'old'=>Session::pullOld()]);}
    public function editForm(string $id): void {$u=$this->requireRole('host');$m=new Listing();$listing=$m->findOwned((int)$id,(int)$u['id']);if(!$listing){http_response_code(404);$this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']);return;}$this->render('host/listing-form',['title'=>'Chỉnh sửa chỗ ở','listing'=>$listing,'photos'=>$m->photos((int)$id,(int)$u['id']),'types'=>$m->types(),'policies'=>$m->policies(),'amenities'=>$m->allAmenities(),'selectedAmenities'=>$m->amenityIds((int)$id),'old'=>Session::pullOld()]);}
    public function availabilityPage(string $id): void
    {
        $user=$this->requireRole('host'); $model=new Listing();
        $listing=$model->findOwned((int)$id,(int)$user['id']);
        if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
        try { $calendar=\Core\Calendar::month($_GET['month']??date('Y-m')); }
        catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); $calendar=\Core\Calendar::month(date('Y-m')); }
        $this->render('host/availability',['title'=>'Lịch khả dụng','listing'=>$listing,
            'dates'=>$model->availability((int)$id,(int)$user['id']),'calendar'=>$calendar,
            'calendarDays'=>$model->calendar((int)$id,(int)$user['id'],$calendar['start'],$calendar['end']),
            'calendarPath'=>'/host/listings/'.$id.'/availability']);
    }

    public function delete(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('host');
        try { (new Listing())->softDelete((int)$id,(int)$user['id']); Session::flash('success','Đã xóa mềm tin. Lịch sử booking và ảnh gốc được giữ nguyên.'); }
        catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/host');
    }

    public function photos(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('host');
        try {
            $model=new Listing();
            if (!$model->findOwned((int)$id,(int)$user['id'])) { throw new \DomainException('Không có quyền quản lý ảnh.'); }
            $positions=$_POST['positions']??[]; $remove=$_POST['remove']??[];
            if (!is_array($positions) || !is_array($remove) || count($positions)>100 || count($remove)>100) { throw new \DomainException('Danh sách ảnh không hợp lệ.'); }
            foreach ($remove as $photoId) { if (!is_scalar($photoId) || !ctype_digit((string)$photoId)) { throw new \DomainException('ID ảnh không hợp lệ.'); } }
            $remove=array_map('intval',$remove); $ordered=[];
            foreach ($positions as $photoId=>$position) {
                if (!ctype_digit((string)$photoId) || filter_var($position,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])===false) { throw new \DomainException('Thứ tự ảnh phải từ 1 đến 100.'); }
                if (!in_array((int)$photoId,$remove,true)) { $ordered[(int)$photoId]=(int)$position; }
            }
            // Require a complete snapshot: omission/malformed payload cannot silently remove photos.
            $current=array_map('intval',array_column($model->photos((int)$id,(int)$user['id']),'id'));
            if (array_diff($current,array_map('intval',array_keys($positions))) || array_diff(array_map('intval',array_keys($positions)),$current)
                || array_diff($remove,$current)) { throw new \DomainException('Danh sách ảnh đã thay đổi. Hãy tải lại trang.'); }
            if (count(array_unique($ordered))!==count($ordered)) { throw new \DomainException('Các ảnh giữ lại phải có thứ tự khác nhau.'); }
            asort($ordered,SORT_NUMERIC);
            $model->managePhotos((int)$id,(int)$user['id'],array_keys($ordered),$current);
            Session::flash('success','Đã cập nhật ảnh và gửi tin duyệt lại. Chỉ gỡ liên kết; không xóa file gốc.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/host/listings/'.$id.'/edit');
    }
    public function store(): never {Csrf::ensure();$u=$this->requireRole('host');$this->persist(null,(int)$u['id']);}
    public function update(string $id): never {Csrf::ensure();$u=$this->requireRole('host');$this->persist((int)$id,(int)$u['id']);}
    private function persist(?int $listingId, int $hostId): never
    {
        $data = $this->listingInput();
        $listingModel = new Listing();
        $returnPath = $listingId ? '/host/listings/'.$listingId.'/edit' : '/host/listings/create';
        $database = \Core\Database::connection();
        try {
            if ($listingId && !$listingModel->findOwned($listingId, $hostId)) {
                throw new \DomainException('Không có quyền sửa chỗ ở.');
            }
            foreach (['title' => 180, 'address' => 255, 'city' => 100, 'description' => 65535] as $field => $maxLength) {
                if ($data[$field] === '' || mb_strlen($data[$field]) > $maxLength) {
                    throw new \DomainException('Vui lòng nhập đầy đủ thông tin trong giới hạn độ dài.');
                }
            }
            if ($data['nightly_price'] <= 0 || $data['cleaning_fee'] < 0
                || $data['nightly_price'] > 9999999999.99 || $data['cleaning_fee'] > 9999999999.99) {
                throw new \DomainException('Giá phải lớn hơn 0 và phụ phí không được âm.');
            }
            foreach (['max_guests', 'room_count', 'bedroom_count', 'bed_count'] as $field) {
                if ($data[$field] < 1 || $data[$field] > 65535) {
                    throw new \DomainException('Sức chứa, số phòng và số giường phải từ 1 đến 65535.');
                }
            }
            foreach (['check_in_time', 'check_out_time'] as $field) {
                if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $data[$field])) {
                    throw new \DomainException('Giờ nhận hoặc trả phòng không hợp lệ.');
                }
            }
            if (!in_array($data['property_type_id'], array_map('intval', array_column($listingModel->types(), 'id')), true)
                || !in_array($data['cancellation_policy_id'], array_map('intval', array_column($listingModel->policies(), 'id')), true)) {
                throw new \DomainException('Loại chỗ ở hoặc chính sách hủy không hợp lệ.');
            }
            $amenities = $_POST['amenities'] ?? [];
            if (!is_array($amenities)) { throw new \DomainException('Tiện nghi không hợp lệ.'); }
            $amenities = array_map('intval', $amenities);
            if (array_diff($amenities, array_map('intval', array_column($listingModel->allAmenities(), 'id')))) {
                throw new \DomainException('Tiện nghi không tồn tại hoặc đã ngừng sử dụng.');
            }

            // Listing, amenities and photo references must commit together.
            $database->beginTransaction();
            if ($listingId) {
                $listingModel->update($listingId, $hostId, $data);
            } else {
                $listingId = $listingModel->create($hostId, $data);
            }
            $listingModel->setAmenities($listingId, $amenities, $hostId);
            $photo = (new UploadService())->image($_FILES['photo'] ?? [], 'listing-'.$listingId);
            if ($photo) { $listingModel->addPhoto($listingId, $photo, $data['title'], $hostId); }
            $database->commit();
            Session::flash('success', 'Đã lưu chỗ ở và chuyển về trạng thái chờ duyệt.');
            $this->redirect('/host');
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) { $database->rollBack(); }
            Session::old($data + ['amenities' => is_array($_POST['amenities'] ?? null) ? array_map('intval', $_POST['amenities']) : []]);
            Session::flash('error', $exception instanceof \DomainException
                ? $exception->getMessage() : 'Không thể lưu chỗ ở. Vui lòng thử lại.');
            $this->redirect($returnPath);
        }
    }
    private function listingInput(): array {return ['property_type_id'=>(int)($_POST['property_type_id']??0),'cancellation_policy_id'=>(int)($_POST['cancellation_policy_id']??0),'title'=>trim((string)($_POST['title']??'')),'address'=>trim((string)($_POST['address']??'')),'city'=>trim((string)($_POST['city']??'')),'description'=>trim((string)($_POST['description']??'')),'nightly_price'=>(float)($_POST['nightly_price']??0),'cleaning_fee'=>(float)($_POST['cleaning_fee']??0),'max_guests'=>(int)($_POST['max_guests']??0),'room_count'=>(int)($_POST['room_count']??1),'bedroom_count'=>(int)($_POST['bedroom_count']??1),'bed_count'=>(int)($_POST['bed_count']??1),'check_in_time'=>(string)($_POST['check_in_time']??'14:00'),'check_out_time'=>(string)($_POST['check_out_time']??'11:00'),'allows_smoking'=>isset($_POST['allows_smoking']),'allows_pets'=>isset($_POST['allows_pets']),'allows_parties'=>isset($_POST['allows_parties'])];}
    public function visibility(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('host');
        try {
            (new Listing())->setVisible((int)$id,(int)$user['id'],!empty($_POST['visible']));
            Session::flash('success','Đã cập nhật hiển thị.');
        } catch (\Throwable $exception) {
            Session::flash('error',$exception instanceof \DomainException ? $exception->getMessage() : 'Không thể cập nhật hiển thị. Vui lòng thử lại.');
        }
        $this->redirect('/host');
    }
    public function availability(string $id): never {Csrf::ensure();$u=$this->requireRole('host');$date=(string)($_POST['date']??'');$valid=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);$message='Ngày không hợp lệ.';$ok=$valid&&$valid->format('Y-m-d')===$date&&$valid>=new \DateTimeImmutable('today');if($ok){$ok=(new Listing())->setAvailability((int)$id,(int)$u['id'],$date,!empty($_POST['open']),(string)($_POST['reason']??''));$message=$ok?'Đã cập nhật lịch.':'Không thể chặn ngày đã có booking xác nhận.';}if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){$this->json($ok,$message,null,[],$ok?200:422);}Session::flash($ok?'success':'error',$message);$this->redirect('/host/listings/'.$id.'/availability');}
    public function transition(string $id): never {Csrf::ensure();$u=$this->requireRole('host');try{(new BookingService())->hostTransition((int)$id,(int)$u['id'],(string)($_POST['status']??''));Session::flash('success','Đã cập nhật booking.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}$this->redirect('/host');}
}

