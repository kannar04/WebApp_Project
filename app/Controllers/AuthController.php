<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\User;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\Session;
use Core\Validator;
final class AuthController extends Controller
{
    public function loginForm(): void {if(Auth::check()){$this->redirect('/');}$next=(string)($_GET['next']??'');if($next===''){ $referer=(string)($_SERVER['HTTP_REFERER']??'');$refererHost=(string)(parse_url($referer,PHP_URL_HOST)??'');$requestHost=explode(':',(string)($_SERVER['HTTP_HOST']??''))[0];if($refererHost===$requestHost){$next=(string)(parse_url($referer,PHP_URL_PATH)??'');}}if($next!==''&&str_starts_with($next,'/')&&!str_starts_with($next,'//')&&!str_starts_with($next,'/login')){Session::put('intended_url',$next);}$this->render('auth/login',['title'=>'Đăng nhập','old'=>Session::pullOld()],'layouts/auth');}
    public function login(): never {Csrf::ensure();$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password===''){Session::old(['email'=>$email]);Session::flash('error','Email hoặc mật khẩu không hợp lệ.');$this->redirect('/login');}if(!Auth::attempt($email,$password)){Session::old(['email'=>$email]);Session::flash('error','Thông tin đăng nhập không đúng hoặc tài khoản đã bị khóa.');$this->redirect('/login');}Session::flash('success','Chào mừng bạn trở lại Home2Home.');$intended=(string)Session::pull('intended_url','');if($intended!==''&&!str_starts_with($intended,'/admin')&&!str_starts_with($intended,'/host')){$this->redirect($intended);}if(Auth::hasRole('admin')){$this->redirect('/admin');}if(Auth::hasRole('host')){$this->redirect('/host');}$this->redirect('/');}
    public function registerForm(): void {if(Auth::check()){$this->redirect('/');}$this->render('auth/register',['title'=>'Tạo tài khoản','old'=>Session::pullOld()],'layouts/auth');}
    public function register(): never {Csrf::ensure();$data=['full_name'=>trim((string)($_POST['full_name']??'')),'email'=>trim((string)($_POST['email']??'')),'phone'=>trim((string)($_POST['phone']??'')),'password'=>(string)($_POST['password']??'')];$errors=Validator::required($data,['full_name'=>'Họ tên','email'=>'Email','password'=>'Mật khẩu']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL)){$errors['email']='Email không hợp lệ.';}if(strlen($data['password'])<8){$errors['password']='Mật khẩu cần ít nhất 8 ký tự.';}if($errors){Session::old(array_diff_key($data,['password'=>1]));Session::flash('error',implode(' ',array_values($errors)));$this->redirect('/register');}try{(new User())->create($data['email'],$data['password'],$data['full_name'],$data['phone']);Auth::attempt($data['email'],$data['password']);Session::flash('success','Tài khoản đã được tạo.');$this->redirect('/');}catch(\PDOException $e){Session::old(array_diff_key($data,['password'=>1]));Session::flash('error',$e->getCode()==='23000'?'Email đã được sử dụng.':'Không thể tạo tài khoản.');$this->redirect('/register');}}
    public function logout(): never {Csrf::ensure();Auth::logout();Session::start();Session::flash('success','Bạn đã đăng xuất.');$this->redirect('/');}
}

