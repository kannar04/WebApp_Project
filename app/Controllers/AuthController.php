<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Trust;
use App\Models\User;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\I18n;
use Core\Session;
use Core\Validator;
final class AuthController extends Controller
{
    public function loginForm(): void {if(Auth::check()){$this->redirectSignedIn();}$this->render('auth/login',['title'=>'Đăng nhập','old'=>Session::pullOld('login'),'returnTarget'=>$this->rememberReturnTarget()],'layouts/auth');}
    public function login(): never {Csrf::ensure();$email=trim(input_text($_POST,'email'));$password=input_password($_POST,'password');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password===''){Session::old(['email'=>$email],'login');Session::flash('error','Email hoặc mật khẩu không hợp lệ.');$this->backToForm('/login');}if(!Auth::attempt($email,$password)){Session::old(['email'=>$email],'login');Session::flash('error','Thông tin đăng nhập không đúng hoặc tài khoản đã bị khóa.');$this->backToForm('/login');}Session::flash('success','Chào mừng bạn trở lại Home2Home.');$this->redirectAfterAuth();}
    public function registerForm(): void {if(Auth::check()){$this->redirectSignedIn();}$this->render('auth/register',['title'=>'Tạo tài khoản','old'=>Session::pullOld('register'),'returnTarget'=>$this->rememberReturnTarget()],'layouts/auth');}
    // Several validation sentences are joined into one flash, so each is translated before joining (Vietnamese is unchanged).
    public function register(): never {Csrf::ensure();$data=['full_name'=>trim(input_text($_POST,'full_name')),'email'=>trim(input_text($_POST,'email')),'phone'=>trim(input_text($_POST,'phone')),'password'=>input_password($_POST,'password')];$errors=Validator::required($data,['full_name'=>'Họ tên','email'=>'Email']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL)){$errors['email']='Email không hợp lệ.';}$passwordProblem=Validator::passwordProblem($data['password']);if($passwordProblem!==null){$errors['password']=$passwordProblem;}if(!Trust::phoneAllowed($data['phone'])){$errors['phone']=Trust::PHONE_RULE;}if(mb_strlen($data['full_name'])>150||strlen($data['phone'])>20||strlen($data['email'])>254){$errors['length']='Thông tin tài khoản vượt giới hạn độ dài.';}if($errors){Session::old(array_diff_key($data,['password'=>1]),'register');Session::flash('error',implode(' ',array_map('__',array_values($errors))));$this->backToForm('/register');}try{(new User())->create($data['email'],$data['password'],$data['full_name'],$data['phone']);Auth::attempt($data['email'],$data['password']);Session::flash('success','Tài khoản đã được tạo.');$this->redirectAfterAuth();}catch(\PDOException $e){Session::old(array_diff_key($data,['password'=>1]),'register');Session::flash('error',$e->getCode()==='23000'?$this->duplicateEmailMessage($data['email']):'Không thể tạo tài khoản.');$this->backToForm('/register');}}
    public function logout(): never {Csrf::ensure();Auth::logout();Session::start();Session::flash('success','Bạn đã đăng xuất.');$this->redirect('/');}
    /** A soft-deleted account keeps its email, so say why the address is taken and who can restore it. */
    private function duplicateEmailMessage(string $email): string
    {
        return ((new User())->findByEmail($email)['deleted_at'] ?? null) !== null
            ? 'Email này thuộc về một tài khoản đã bị xóa. Vui lòng liên hệ quản trị viên để khôi phục.'
            : 'Email đã được sử dụng.';
    }
    /**
     * Where this sign-in form returns, for its hidden next and its links. An explicit next wins but stays with the page that carries it: kept in the session it would steer every later sign-in to an abandoned page, and each tab would overwrite the others.
     * Otherwise a protected page's stored target stays, and the Referer only fills in when neither exists.
     */
    private function rememberReturnTarget(): string
    {
        $next = $this->returnTarget(input_text($_GET, 'next'));
        if ($next !== '') { return $next; }
        if (($_SESSION['intended_url'] ?? '') !== '') { return (string) $_SESSION['intended_url']; }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
        if ((string) (parse_url($referer, PHP_URL_HOST) ?? '') !== $requestHost) { return ''; }
        $target = $this->returnTarget((string) app_path((string) (parse_url($referer, PHP_URL_PATH) ?? '')));
        // The home page is where sign-in links start, not a page to return to; it also replaces an older Referer, and each role then goes to its own home.
        if ($target === '/') { Session::pull('referer_url'); return ''; }
        if ($target !== '') { Session::put('referer_url', $target); }
        return '';
    }
    /** Back to a refused form, which still returns where it was going. */
    private function backToForm(string $path): never
    {
        $target = $this->returnTarget(input_text($_POST, 'next'));
        $this->redirect($path . ($target !== '' ? '?next=' . rawurlencode($target) : ''));
    }
    /** Already signed in, in another tab for instance: a valid next still leads to the chosen page, anything else to the home page. */
    private function redirectSignedIn(): never
    {
        $target = $this->returnTarget(input_text($_GET, 'next'));
        $this->redirect($this->mayFollow($target) ? $target : '/');
    }
    /** Sends a signed-in user to the page their form was returning to, otherwise the remembered one, otherwise to the home of their role. */
    private function redirectAfterAuth(): never
    {
        $fromReferer = $this->returnTarget((string) Session::pull('referer_url', ''));
        $target = $this->returnTarget((string) Session::pull('intended_url', ''));
        $fromForm = $this->returnTarget(input_text($_POST, 'next'));
        $target = $fromForm !== '' ? $fromForm : ($target !== '' ? $target : $fromReferer);
        if ($this->mayFollow($target)) { $this->redirect($target); }
        if (Auth::hasRole('admin')) { $this->redirect('/admin'); }
        if (Auth::hasRole('host')) { $this->redirect('/host'); }
        $this->redirect('/');
    }
    private function mayFollow(string $target): bool
    {
        return $target !== '' && !str_starts_with($target, '/admin') && !str_starts_with($target, '/host');
    }
    /** The candidate when it may be returned to after signing in, otherwise ''. Auth pages never qualify, so no redirect leads back to them. */
    private function returnTarget(string $candidate): string
    {
        if (I18n::safeReturnPath($candidate) !== $candidate) { return ''; }
        $readable = parse_url($candidate, PHP_URL_PATH);
        if ($readable === false) { return ''; }
        $path = rtrim(rawurldecode((string) $readable), '/');
        return in_array($path, ['/login', '/register', '/forgot-password', '/reset-password'], true) ? '' : $candidate;
    }
    public function forgotForm(): void
    {
        $this->render('auth/forgot',['title'=>'Khôi phục mật khẩu','mailReady'=>(new \App\Services\PasswordRecoveryService())->configured(),'old'=>Session::pullOld('forgot'),'returnTarget'=>$this->returnTarget(input_text($_GET,'next'))],'layouts/auth');
    }
    public function forgot(): never
    {
        Csrf::ensure(); $email=$_POST['email']??'';
        try {
            if (!is_string($email) || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new \DomainException('Email không hợp lệ.'); }
            (new \App\Services\PasswordRecoveryService())->request($email);
            Session::flash('success','Nếu tài khoản đang hoạt động và dịch vụ gửi mail được cấu hình, bạn sẽ nhận hướng dẫn khôi phục. Kiểm tra thư rác hoặc liên hệ quản trị viên nếu chưa nhận được.');
        } catch (\DomainException $exception) { Session::old(['email'=>mb_substr(input_text($_POST,'email'),0,254)],'forgot'); Session::flash('error',$exception->getMessage()); }
        $this->backToForm('/forgot-password');
    }
    public function resetForm(): void
    {
        header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
        $token=$_GET['token']??'';
        // A used, expired or unknown link says so on opening, before the password is typed twice. Asking spends nothing.
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token) || !(new \App\Models\PasswordReset())->usable($token)) { http_response_code(422); $token=''; }
        $this->render('auth/reset',['title'=>'Đặt mật khẩu mới','resetToken'=>$token],'layouts/auth');
    }
    public function reset(): never
    {
        $token=$_POST['token']??'';
        $tokenIsWellFormed=is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)===1;
        // The reset form sends no Referer (see resetForm), so a stale form needs telling where it came from. Only a well-formed token goes into the redirect.
        Csrf::ensure($tokenIsWellFormed ? '/reset-password?token='.$token : '/');
        try {
            $password=$_POST['password']??''; $confirmation=$_POST['password_confirmation']??'';
            if (!$tokenIsWellFormed) { throw new \DomainException('Liên kết không hợp lệ, đã hết hạn hoặc đã sử dụng.'); }
            // A typing mistake keeps the link: the form stays open and the token is not spent.
            $problem=!is_string($password) || $password!==$confirmation ? 'Xác nhận mật khẩu không khớp.' : Validator::passwordProblem($password);
            if ($problem!==null) { Session::flash('error',$problem); $this->redirect('/reset-password?token='.$token); }
            (new \App\Models\PasswordReset())->consume($token,$password);
            if (Auth::check()) { Auth::logout(); Session::start(); }
            Session::flash('success','Đã đặt mật khẩu mới. Vui lòng đăng nhập lại.'); $this->redirect('/login');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/forgot-password');
    }
}

