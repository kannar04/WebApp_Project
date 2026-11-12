<?php
declare(strict_types=1);
namespace App\Services;

final class GoogleAuth
{
    public function configured(): bool
    {
        $config = require dirname(__DIR__,2).'/config/google.php';
        $uri = parse_url($config['redirect_uri']);
        $secure = ($uri['scheme'] ?? '') === 'https'
            || (($uri['scheme'] ?? '') === 'http' && in_array($uri['host'] ?? '', ['localhost','127.0.0.1','::1'], true));
        return $config['client_id'] !== '' && $config['client_secret'] !== '' && $secure
            && !empty($uri['host']) && !isset($uri['user']) && !isset($uri['pass']) && !isset($uri['fragment']) && !isset($uri['query']);
    }

    public function begin(string $next, ?int $linkUserId = null, ?string $authVersion = null): string
    {
        if (!$this->configured()) { throw new \DomainException('Đăng nhập Google hiện chưa khả dụng. Vui lòng dùng email và mật khẩu.'); }
        $config = require dirname(__DIR__,2).'/config/google.php';
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $attempts = $_SESSION['_google_oauth'] ?? [];
        foreach ($attempts as $key => $attempt) { if (($attempt['expires'] ?? 0) < time()) { unset($attempts[$key]); } }
        while (count($attempts) >= 5) { array_shift($attempts); }
        $attempts[$state] = ['expires'=>time()+600, 'verifier'=>$verifier, 'next'=>$next,
            'user_id'=>$linkUserId, 'auth_version'=>$authVersion];
        $_SESSION['_google_oauth'] = $attempts;
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$config['client_id'], 'redirect_uri'=>$config['redirect_uri'],
            'response_type'=>'code', 'scope'=>'openid email profile', 'state'=>$state,
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)), '+/', '-_'), '='),
            'code_challenge_method'=>'S256', 'prompt'=>'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Single-use, browser-bound state. A callback cannot move between accounts. */
    public function consume(string $state, ?int $currentUserId, ?string $authVersion): array
    {
        $attempt = preg_match('/^[a-f0-9]{64}$/D',$state) ? ($_SESSION['_google_oauth'][$state] ?? null) : null;
        unset($_SESSION['_google_oauth'][$state]);
        if (!is_array($attempt) || $attempt['expires'] < time()
            || $attempt['user_id'] !== $currentUserId
            || ($currentUserId !== null && !hash_equals((string)$attempt['auth_version'], (string)$authVersion))) {
            throw new \DomainException('Phiên đăng nhập Google không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.');
        }
        return $attempt;
    }

    public function profile(string $code, string $verifier): array
    {
        if (!$this->configured() || $code === '' || strlen($code) > 4096 || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D',$verifier)) {
            throw new \DomainException('Không thể xác thực tài khoản Google. Vui lòng thử lại.');
        }
        $config = require dirname(__DIR__,2).'/config/google.php';
        $token = $this->request('https://oauth2.googleapis.com/token', [
            'client_id'=>$config['client_id'], 'client_secret'=>$config['client_secret'],
            'code'=>$code, 'code_verifier'=>$verifier, 'grant_type'=>'authorization_code',
            'redirect_uri'=>$config['redirect_uri'],
        ]);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === ''
            || strcasecmp((string)($token['token_type'] ?? ''), 'Bearer') !== 0) {
            throw new \DomainException('Không thể xác thực tài khoản Google. Vui lòng thử lại.');
        }
        // Identity comes only from Google's HTTPS UserInfo endpoint, never browser-submitted claims.
        $info = $this->request('https://openidconnect.googleapis.com/v1/userinfo', null, $token['access_token']);
        if (!is_string($info['sub'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$info['sub'])) {
            throw new \DomainException('Không thể xác thực tài khoản Google. Vui lòng thử lại.');
        }
        $email = is_string($info['email'] ?? null) ? mb_strtolower(trim($info['email'])) : '';
        if (($info['email_verified'] ?? false) !== true || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { $email=''; }
        $name = is_string($info['name'] ?? null) ? mb_substr(trim($info['name']),0,150) : '';
        return ['subject'=>$info['sub'], 'email'=>$email, 'name'=>$name !== '' ? $name : 'Google user'];
    }

    private function request(string $url, ?array $form = null, ?string $token = null): array
    {
        $request = curl_init($url);
        if ($request === false) { throw new \RuntimeException('Google request unavailable.'); }
        $headers = ['Accept: application/json'];
        if ($token !== null) { $headers[] = 'Authorization: Bearer '.$token; }
        curl_setopt_array($request, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'Home2Home/1.0']);
        if ($form !== null) { curl_setopt_array($request,[CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($form)]); }
        if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) { curl_setopt_array($request,[CURLOPT_SSL_OPTIONS=>CURLSSLOPT_NATIVE_CA]); }
        $response = curl_exec($request);
        $status = curl_getinfo($request,CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $body = is_string($response) ? json_decode($response,true) : null;
        if ($status !== 200 || !is_array($body) || isset($body['error'])) {
            throw new \DomainException('Không thể kết nối Google. Vui lòng thử lại hoặc đăng nhập bằng mật khẩu.');
        }
        return $body;
    }
}
