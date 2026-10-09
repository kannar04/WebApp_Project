<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\AdminRepository;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use Core\Controller;
use Core\Csrf;
use Core\Database;
use Core\Session;
final class AdminController extends Controller
{
    public function dashboard(): void {$this->requireRole('admin');$repo=new AdminRepository();$this->render('admin/dashboard',['title'=>'Quản trị Home2Home','stats'=>$repo->stats(),'users'=>(new User())->all((string)($_GET['q']??'')),'listings'=>$repo->listings(),'bookings'=>(new Booking())->all()]);}
    public function userStatus(string $id): never
    {
        Csrf::ensure(); $admin=$this->requireRole('admin'); $status=(string)($_POST['status']??'');
        if (!in_array($status,['active','suspended'],true)) { Session::flash('error','Trạng thái tài khoản không hợp lệ.'); $this->redirect('/admin'); }
        $this->managedUserChange((int)$id,(int)$admin['id'],'set_user_status',$status,
            fn()=>(new User())->setStatus((int)$id,$status),'Đã cập nhật tài khoản.');
    }
    public function userRoles(string $id): never
    {
        Csrf::ensure(); $admin=$this->requireRole('admin'); $roles=$_POST['roles']??[];
        if (!is_array($roles) || count($roles)>3 || array_filter($roles,fn($role)=>!is_string($role)||!in_array($role,['guest','host','admin'],true))) {
            Session::flash('error','Vai trò không hợp lệ.'); $this->redirect('/admin');
        }
        $this->managedUserChange((int)$id,(int)$admin['id'],'set_user_roles',implode(',',$roles),
            fn()=>(new User())->replaceRoles((int)$id,$roles),'Đã cập nhật vai trò.');
    }
    public function userCreate(): never
    {
        Csrf::ensure(); $admin=$this->requireRole('admin');
        $data=['full_name'=>trim((string)($_POST['full_name']??'')),'email'=>trim((string)($_POST['email']??'')),
            'phone'=>trim((string)($_POST['phone']??'')),'password'=>(string)($_POST['password']??'')];
        $roles=$_POST['roles']??['guest'];
        if ($data['full_name']==='' || mb_strlen($data['full_name'])>150 || !filter_var($data['email'],FILTER_VALIDATE_EMAIL)
            || strlen($data['email'])>254 || strlen($data['phone'])>20 || strlen($data['password'])<8 || !is_array($roles)
            || array_filter($roles,fn($role)=>!is_string($role)||!in_array($role,['guest','host','admin'],true))) {
            Session::flash('error','Thông tin tài khoản mới không hợp lệ.'); $this->redirect('/admin');
        }
        $database=Database::connection(); $database->beginTransaction();
        try {
            $newId=(new User())->adminCreate($data,$roles);
            (new AdminRepository())->audit((int)$admin['id'],'create_user','user',$newId,$data['email']);
            $database->commit(); Session::flash('success','Đã tạo tài khoản.');
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) { $database->rollBack(); }
            Session::flash('error','Không thể tạo tài khoản. Kiểm tra email đã tồn tại và thử lại.');
        }
        $this->redirect('/admin');
    }
    public function userEdit(string $id): void {$this->requireRole('admin');$user=(new User())->findForAdmin((int)$id);if(!$user){http_response_code(404);$this->render('errors/404',['title'=>'Không tìm thấy tài khoản']);return;}$this->render('admin/user-edit',['title'=>'Sửa tài khoản','managedUser'=>$user,'old'=>Session::pullOld()]);}
    public function userUpdate(string $id): never
    {
        Csrf::ensure();
        $admin = $this->requireRole('admin');
        $userId = (int) $id;
        $userModel = new User();
        if (!$userModel->findForAdmin($userId)) {
            Session::flash('error', 'Tài khoản không tồn tại.');
            $this->redirect('/admin');
        }
        $data = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
        ];
        if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150
            || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 254
            || strlen($data['phone']) > 20
            || ($data['password'] !== '' && strlen($data['password']) < 8)) {
            Session::old(array_diff_key($data, ['password'=>1]));
            Session::flash('error', 'Thông tin cập nhật không hợp lệ; mật khẩu mới cần ít nhất 8 ký tự.');
            $this->redirect('/admin/users/'.$userId.'/edit');
        }
        $roles = $_POST['roles'] ?? ['guest'];
        if (!is_array($roles)) {
            Session::flash('error', 'Vai trò không hợp lệ.');
            $this->redirect('/admin/users/'.$userId.'/edit');
        }
        // The profile edit endpoint must enforce the same self-demotion guard as role editing.
        if ($userId === (int) $admin['id']) {
            $roles = $admin['roles'];
        }
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $userModel->adminUpdate($userId, $data);
            $userModel->replaceRoles($userId, $roles);
            (new AdminRepository())->audit((int) $admin['id'], 'update_user', 'user', $userId, $data['email']);
            $database->commit();
            Session::flash('success', 'Đã cập nhật tài khoản.');
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) { $database->rollBack(); }
            $message = $exception instanceof \PDOException && $exception->getCode() === '23000'
                ? 'Email đã được sử dụng.' : 'Không thể cập nhật tài khoản. Vui lòng thử lại.';
            Session::old(array_diff_key($data, ['password'=>1]) + ['roles'=>$roles]);
            Session::flash('error', $message);
            $this->redirect('/admin/users/'.$userId.'/edit');
        }
        $this->redirect('/admin');
    }
    public function userDelete(string $id): never
    {
        Csrf::ensure(); $admin=$this->requireRole('admin');
        $this->managedUserChange((int)$id,(int)$admin['id'],'delete_user','Soft delete',
            fn()=>(new User())->softDelete((int)$id),'Đã xóa mềm tài khoản.');
    }

    private function managedUserChange(int $id, int $adminId, string $action, string $details, callable $operation, string $message): never
    {
        $database=Database::connection(); $ownsTransaction=false;
        try {
            if ($id===$adminId) { throw new \DomainException('Không thể thay đổi trạng thái hoặc vai trò của Admin đang đăng nhập.'); }
            if (!(new User())->findForAdmin($id)) { throw new \DomainException('Tài khoản không tồn tại.'); }
            $ownsTransaction=!$database->inTransaction();
            if ($ownsTransaction) { $database->beginTransaction(); }
            $operation();
            (new AdminRepository())->audit($adminId,$action,'user',$id,$details);
            if ($ownsTransaction) { $database->commit(); }
            Session::flash('success',$message);
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $database->inTransaction()) { $database->rollBack(); }
            Session::flash('error',$exception instanceof \DomainException ? $exception->getMessage() : 'Không thể cập nhật tài khoản. Vui lòng thử lại.');
        }
        $this->redirect('/admin');
    }
    public function moderate(string $id): never {Csrf::ensure();$admin=$this->requireRole('admin');$action=(string)($_POST['action']??'');$note=trim((string)($_POST['note']??''));if(!in_array($action,['approved','rejected'],true)||($action==='rejected'&&$note==='')){Session::flash('error','Từ chối bài đăng phải có lý do.');$this->redirect('/admin');}try{(new AdminRepository())->moderate((int)$id,(int)$admin['id'],$action,$note);Session::flash('success','Đã cập nhật kiểm duyệt bài đăng.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể kiểm duyệt bài đăng. Vui lòng thử lại.');}$this->redirect('/admin');}
    public function listingEdit(string $id): void {$this->requireRole('admin');$listing=(new AdminRepository())->findListing((int)$id);if(!$listing){http_response_code(404);$this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']);return;}$this->render('admin/listing-edit',['title'=>'Sửa chỗ ở','listing'=>$listing,'old'=>Session::pullOld()]);}
    public function listingUpdate(string $id): never
    {
        Csrf::ensure();
        $admin=$this->requireRole('admin');
        $data=['title'=>trim((string)($_POST['title']??'')),'address'=>trim((string)($_POST['address']??'')),
            'city'=>trim((string)($_POST['city']??'')),'description'=>trim((string)($_POST['description']??'')),
            'nightly_price'=>(float)($_POST['nightly_price']??0),'cleaning_fee'=>(float)($_POST['cleaning_fee']??0),
            'max_guests'=>(int)($_POST['max_guests']??0)];
        try {
            foreach (['title'=>180,'address'=>255,'city'=>100,'description'=>65535] as $field=>$maximum) {
                if ($data[$field]==='' || mb_strlen($data[$field])>$maximum) { throw new \DomainException('Vui lòng nhập đầy đủ thông tin trong giới hạn độ dài.'); }
            }
            if ($data['nightly_price']<=0 || $data['nightly_price']>9999999999.99 || $data['cleaning_fee']<0
                || $data['cleaning_fee']>9999999999.99 || $data['max_guests']<1 || $data['max_guests']>65535) {
                throw new \DomainException('Giá phải lớn hơn 0, phụ phí không được âm và sức chứa phải hợp lệ.');
            }
            (new AdminRepository())->updateListing((int)$id,(int)$admin['id'],$data);
            Session::flash('success','Đã cập nhật chỗ ở.');
            $this->redirect('/admin');
        } catch (\Throwable $exception) {
            Session::old($data);
            Session::flash('error',$exception instanceof \DomainException ? $exception->getMessage() : 'Không thể lưu chỗ ở. Vui lòng thử lại.');
            $this->redirect('/admin/listings/'.$id.'/edit');
        }
    }
    public function listingVisibility(string $id): never {Csrf::ensure();$admin=$this->requireRole('admin');(new AdminRepository())->setListingVisibility((int)$id,(int)$admin['id'],!empty($_POST['visible']));Session::flash('success','Đã cập nhật hiển thị listing.');$this->redirect('/admin');}
    public function listingDelete(string $id): never {Csrf::ensure();$admin=$this->requireRole('admin');(new AdminRepository())->softDeleteListing((int)$id,(int)$admin['id']);Session::flash('success','Đã gỡ listing (soft delete).');$this->redirect('/admin');}
    public function bookingTransition(string $id): never {Csrf::ensure();$admin=$this->requireRole('admin');try{(new BookingService())->adminTransition((int)$id,(int)$admin['id'],(string)($_POST['status']??''));Session::flash('success','Đã cập nhật booking.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}$this->redirect('/admin');}
}

