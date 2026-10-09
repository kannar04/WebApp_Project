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
    public function show(): void {$user=$this->requireAuth();$upcoming=array_filter((new Booking())->forGuest((int)$user['id']),fn($b)=>in_array($b['status'],['pending','confirmed'],true));$this->render('profile/show',['title'=>'Tài khoản','user'=>$user,'upcoming'=>$upcoming]);}
    public function update(): never {Csrf::ensure();$user=$this->requireAuth();$name=trim((string)($_POST['full_name']??''));$phone=trim((string)($_POST['phone']??''));if($name===''){Session::flash('error','Họ tên không được để trống.');$this->redirect('/profile');}try{$avatar=(new UploadService())->image($_FILES['avatar']??[],'avatar-'.(int)$user['id']);(new User())->updateProfile((int)$user['id'],$name,$phone,$avatar);Session::flash('success','Đã cập nhật hồ sơ.');}catch(\Throwable $e){Session::flash('error',$e->getMessage());}$this->redirect('/profile');}
    public function becomeHost(): never {Csrf::ensure();$user=$this->requireAuth();(new User())->grantHost((int)$user['id']);Session::flash('success','Vai trò Host đã được kích hoạt.');$this->redirect('/host');}
}

