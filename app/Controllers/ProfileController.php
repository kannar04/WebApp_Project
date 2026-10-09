<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Booking;
use App\Models\User;
use App\Services\UploadService;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\Session;
final class ProfileController extends Controller
{
    public function show(): void
    {
        $user = $this->requireAuth();
        $upcoming = array_filter((new Booking())->forGuest((int)$user['id']), fn($b) => in_array($b['status'], ['pending', 'confirmed'], true));
        $this->render('profile/show', ['title' => 'Tài khoản', 'user' => $user, 'upcoming' => $upcoming, 'old' => Session::pull('_profile_old', [])]);
    }

    public function update(): never
    {
        Csrf::ensure();
        $user = $this->requireAuth();
        $rawName = $_POST['full_name'] ?? '';
        $rawPhone = $_POST['phone'] ?? '';
        $name = is_string($rawName) ? trim($rawName) : '';
        $phone = is_string($rawPhone) ? trim($rawPhone) : '';
        if (!is_string($rawName) || !is_string($rawPhone) || $name === '' || mb_strlen($name) > 150 || strlen($phone) > 20) {
            Session::put('_profile_old', ['full_name' => $name, 'phone' => $phone]);
            Session::flash('error', 'Họ tên và số điện thoại không hợp lệ hoặc vượt giới hạn độ dài.');
            $this->redirect('/profile#personal');
        }
        try {
            $avatar = (new UploadService())->image($_FILES['avatar'] ?? [], 'avatar-'.(int)$user['id']);
            (new User())->updateProfile((int)$user['id'], $name, $phone, $avatar);
            Session::pull('_profile_old');
            Session::flash('success', 'Đã cập nhật hồ sơ.');
        } catch (\Throwable $e) {
            Session::put('_profile_old', ['full_name' => $name, 'phone' => $phone]);
            Session::flash('error', $e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');
        }
        $this->redirect('/profile#personal');
    }
    public function becomeHost(): never {Csrf::ensure();$user=$this->requireAuth();(new User())->grantHost((int)$user['id']);Session::flash('success','Vai trò Host đã được kích hoạt.');$this->redirect('/host');}
    public function password(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try {
            $current=$_POST['current_password']??''; $new=$_POST['new_password']??''; $confirmation=$_POST['password_confirmation']??'';
            if (!is_string($current) || !is_string($new) || !is_string($confirmation) || $new!==$confirmation) { throw new \DomainException('Xác nhận mật khẩu không khớp.'); }
            (new User())->changePassword((int)$user['id'],$user['email'],$current,$new);
            Auth::logout(); Session::start(); Session::flash('success','Đã đổi mật khẩu. Các phiên cũ không còn hợp lệ; vui lòng đăng nhập lại.');
            $this->redirect('/login');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/profile');
    }
}

