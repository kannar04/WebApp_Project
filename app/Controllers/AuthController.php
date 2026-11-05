<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\User;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\I18n;
use Core\Session;
use Core\Validator;
final class AuthController extends Controller
{
    public function loginForm(): void {if(Auth::check()){$this->redirect('/');}$this->rememberReturnTarget();$this->render('auth/login',['title'=>'Đăng nhập','old'=>Session::pullOld(),'returnTarget'=>(string)($_SESSION['intended_url']??'')],'layouts/auth');}
    public function login(): never {Csrf::ensure();$email=trim(input_text($_POST,'email'));$password=input_text($_POST,'password');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password===''){Session::old(['email'=>$email]);Session::flash('error','Email hoặc mật khẩu không hợp lệ.');$this->redirect('/login');}if(!Auth::attempt($email,$password)){Session::old(['email'=>$email]);Session::flash('error','Thông tin đăng nhập không đúng hoặc tài khoản đã bị khóa.');$this->redirect('/login');}Session::flash('success','Chào mừng bạn trở lại Home2Home.');$this->redirectAfterAuth();}
    public function registerForm(): void {if(Auth::check()){$this->redirect('/');}$this->rememberReturnTarget();$this->render('auth/register',['title'=>'Tạo tài khoản','old'=>Session::pullOld(),'returnTarget'=>(string)($_SESSION['intended_url']??'')],'layouts/auth');}
    // Several validation sentences are joined into one flash, so each is translated before joining (Vietnamese is unchanged).
    public function register(): never {Csrf::ensure();$data=['full_name'=>trim(input_text($_POST,'full_name')),'email'=>trim(input_text($_POST,'email')),'phone'=>trim(input_text($_POST,'phone')),'password'=>input_text($_POST,'password')];$errors=Validator::required($data,['full_name'=>'Họ tên','email'=>'Email','password'=>'Mật khẩu']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL)){$errors['email']='Email không hợp lệ.';}if(!Validator::passwordAllowed($data['password'])){$errors['password']=Validator::PASSWORD_RULE;}if(mb_strlen($data['full_name'])>150||strlen($data['phone'])>20||strlen($data['email'])>254){$errors['length']='Thông tin tài khoản vượt giới hạn độ dài.';}if($errors){Session::old(array_diff_key($data,['password'=>1]));Session::flash('error',implode(' ',array_map('__',array_values($errors))));$this->redirect('/register');}try{(new User())->create($data['email'],$data['password'],$data['full_name'],$data['phone']);Auth::attempt($data['email'],$data['password']);Session::flash('success','Tài khoản đã được tạo.');$this->redirectAfterAuth();}catch(\PDOException $e){Session::old(array_diff_key($data,['password'=>1]));Session::flash('error',$e->getCode()==='23000'?$this->duplicateEmailMessage($data['email']):'Không thể tạo tài khoản.');$this->redirect('/register');}}
    public function logout(): never {Csrf::ensure();Auth::logout();Session::start();Session::flash('success','Bạn đã đăng xuất.');$this->redirect('/');}
    /** A soft-deleted account keeps its email, so say why the address is taken and who can restore it. */
    private function duplicateEmailMessage(string $email): string
    {
        return ((new User())->findByEmail($email)['deleted_at'] ?? null) !== null
            ? 'Email này thuộc về một tài khoản đã bị xóa. Vui lòng liên hệ quản trị viên để khôi phục.'
            : 'Email đã được sử dụng.';
    }
    /** Remembers where to return after signing in: an explicit next wins, a protected page's stored target stays, and the Referer only fills in when neither exists. */
    private function rememberReturnTarget(): void
    {
        $next = input_text($_GET, 'next');
        if ($next !== '') {
            $target = $this->returnTarget($next);
            if ($target !== '') { Session::put('intended_url', $target); }
            return;
        }
        if (($_SESSION['intended_url'] ?? '') !== '') { return; }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
        if ((string) (parse_url($referer, PHP_URL_HOST) ?? '') !== $requestHost) { return; }
        $target = $this->returnTarget((string) app_path((string) (parse_url($referer, PHP_URL_PATH) ?? '')));
        // The home page is where sign-in links start, not a page to return to; it also replaces an older Referer, and each role then goes to its own home.
        if ($target === '/') { Session::pull('referer_url'); return; }
        if ($target !== '') { Session::put('referer_url', $target); }
    }
    /** Sends a signed-in user to the remembered page, otherwise to the home of their role. */
    private function redirectAfterAuth(): never
    {
        $fromReferer = $this->returnTarget((string) Session::pull('referer_url', ''));
        $target = $this->returnTarget((string) Session::pull('intended_url', ''));
        if ($target === '') { $target = $fromReferer; }
        if ($target !== '' && !str_starts_with($target, '/admin') && !str_starts_with($target, '/host')) { $this->redirect($target); }
        if (Auth::hasRole('admin')) { $this->redirect('/admin'); }
        if (Auth::hasRole('host')) { $this->redirect('/host'); }
        $this->redirect('/');
    }
    /** The candidate when it may be returned to after signing in, otherwise ''. Auth pages never qualify, so no redirect leads back to them. */
    private function returnTarget(string $candidate): string
    {
        if (I18n::safeReturnPath($candidate) !== $candidate) { return ''; }
        $path = rtrim(rawurldecode((string) (parse_url($candidate, PHP_URL_PATH) ?? '')), '/');
        return in_array($path, ['/login', '/register', '/forgot-password', '/reset-password'], true) ? '' : $candidate;
    }
    public function forgotForm(): void
    {
        $this->render('auth/forgot',['title'=>'Khôi phục mật khẩu','mailReady'=>(new \App\Services\PasswordRecoveryService())->configured()],'layouts/auth');
    }
    public function forgot(): never
    {
        Csrf::ensure(); $email=$_POST['email']??'';
        try {
            if (!is_string($email) || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new \DomainException('Email không hợp lệ.'); }
            (new \App\Services\PasswordRecoveryService())->request($email);
            Session::flash('success','Nếu tài khoản đang hoạt động và dịch vụ gửi mail được cấu hình, bạn sẽ nhận hướng dẫn khôi phục. Kiểm tra thư rác hoặc liên hệ quản trị viên nếu chưa nhận được.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/forgot-password');
    }
    public function resetForm(): void
    {
        header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
        $token=$_GET['token']??'';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) { http_response_code(422); $token=''; }
        $this->render('auth/reset',['title'=>'Đặt mật khẩu mới','resetToken'=>$token],'layouts/auth');
    }
    public function reset(): never
    {
        Csrf::ensure();
        try {
            $token=$_POST['token']??''; $password=$_POST['password']??''; $confirmation=$_POST['password_confirmation']??'';
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new \DomainException('Liên kết không hợp lệ, đã hết hạn hoặc đã sử dụng.'); }
            // A typing mistake keeps the link: the form stays open and the token is not spent.
            $problem=!is_string($password) || $password!==$confirmation ? 'Xác nhận mật khẩu không khớp.' : (Validator::passwordAllowed($password) ? null : Validator::PASSWORD_RULE);
            if ($problem!==null) { Session::flash('error',$problem); $this->redirect('/reset-password?token='.$token); }
            (new \App\Models\PasswordReset())->consume($token,$password);
            if (Auth::check()) { Auth::logout(); Session::start(); }
            Session::flash('success','Đã đặt mật khẩu mới. Vui lòng đăng nhập lại.'); $this->redirect('/login');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/forgot-password');
    }
}

