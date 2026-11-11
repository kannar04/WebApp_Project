<?php
declare(strict_types=1);
namespace App\Services;

final class FacebookAuth
{
    public const UNAVAILABLE='Đăng nhập Facebook hiện chưa khả dụng. Vui lòng dùng Google hoặc email và mật khẩu.';
    public const INVALID='Không thể xác thực tài khoản Facebook. Vui lòng thử lại.';

    public function configured(): bool
    {
        $config=require dirname(__DIR__,2).'/config/facebook.php';
        $uri=parse_url($config['redirect_uri']);
        return $config['enabled']&&function_exists('curl_init')&&preg_match('/^[0-9]+$/D',$config['app_id'])&&$config['app_secret']!==''
            &&preg_match('/^v[1-9][0-9]*\.[0-9]+$/D',$config['version'])&&is_array($uri)&&!empty($uri['host'])
            &&!isset($uri['user'])&&!isset($uri['pass'])&&!isset($uri['query'])&&!isset($uri['fragment'])
            &&(($uri['scheme']??'')==='https'||(($uri['scheme']??'')==='http'&&in_array($uri['host'],['localhost','127.0.0.1','::1'],true)));
    }

    public function begin(string $next,?int $linkUserId=null,?string $authVersion=null): string
    {
        if(!$this->configured()){throw new \DomainException(self::UNAVAILABLE);}
        $config=require dirname(__DIR__,2).'/config/facebook.php';
        $attempts=$_SESSION['_facebook_oauth']??[];
        foreach($attempts as $key=>$attempt){if(($attempt['expires']??0)<=time()){unset($attempts[$key]);}}
        while(count($attempts)>=5){array_shift($attempts);}
        $state=bin2hex(random_bytes(32));
        $attempts[$state]=['expires'=>time()+600,'next'=>$next,'user_id'=>$linkUserId,'auth_version'=>$authVersion];
        $_SESSION['_facebook_oauth']=$attempts;
        return 'https://www.facebook.com/'.$config['version'].'/dialog/oauth?'.http_build_query([
            'client_id'=>$config['app_id'],'redirect_uri'=>$config['redirect_uri'],'response_type'=>'code',
            'scope'=>'public_profile,email','state'=>$state], '', '&',PHP_QUERY_RFC3986);
    }

    public function consume(string $state,?int $currentUserId,?string $authVersion): array
    {
        $attempt=preg_match('/^[a-f0-9]{64}$/D',$state)?($_SESSION['_facebook_oauth'][$state]??null):null;
        unset($_SESSION['_facebook_oauth'][$state]);
        if(!is_array($attempt)||$attempt['expires']<=time()||$attempt['user_id']!==$currentUserId
            ||($currentUserId!==null&&!hash_equals((string)$attempt['auth_version'],(string)$authVersion))){
            throw new \DomainException('Phiên đăng nhập Facebook không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.');
        }
        return $attempt;
    }

    public function profile(string $code): array
    {
        if(!$this->configured()||$code===''||strlen($code)>4096){throw new \DomainException(self::INVALID);}
        $config=require dirname(__DIR__,2).'/config/facebook.php';
        $base='https://graph.facebook.com/'.$config['version'];
        $token=$this->request($base.'/oauth/access_token',[
            'client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],
            'redirect_uri'=>$config['redirect_uri'],'code'=>$code]);
        $access=$token['access_token']??null;
        if(!is_string($access)||$access===''||strcasecmp((string)($token['token_type']??''),'bearer')!==0){throw new \DomainException(self::INVALID);}
        // Check the app-bound token, then obtain identity only from Meta's server.
        $debug=$this->request($base.'/debug_token?'.http_build_query(['input_token'=>$access]),null,$config['app_id'].'|'.$config['app_secret']);
        $data=$debug['data']??[];
        if(!is_array($data)||($data['is_valid']??false)!==true||(string)($data['app_id']??'')!==$config['app_id']
            ||!is_string($data['user_id']??null)||!preg_match('/^[0-9]{1,64}$/D',$data['user_id'])
            ||!is_numeric($data['expires_at']??null)||(int)$data['expires_at']<=time()
            ||(isset($data['data_access_expires_at'])&&(int)$data['data_access_expires_at']>0&&(int)$data['data_access_expires_at']<=time())){
            throw new \DomainException(self::INVALID);
        }
        $info=$this->request($base.'/me?'.http_build_query(['fields'=>'id,name,email',
            'appsecret_proof'=>hash_hmac('sha256',$access,$config['app_secret'])]),null,$access);
        if(($info['id']??null)!==$data['user_id']){throw new \DomainException(self::INVALID);}
        $email=is_string($info['email']??null)?mb_strtolower(trim($info['email'])):'';
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)){$email='';}
        $name=is_string($info['name']??null)?mb_substr(trim($info['name']),0,150):'';
        return ['subject'=>$data['user_id'],'email'=>$email,'name'=>$name!==''?$name:'Facebook user'];
    }

    private function request(string $url,?array $form=null,?string $token=null): array
    {
        $request=curl_init($url);
        if($request===false){throw new \DomainException(self::INVALID);}
        $headers=['Accept: application/json'];if($token!==null){$headers[]='Authorization: Bearer '.$token;}
        try{
            curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
                CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'Home2Home/1.0']);
            if($form!==null){curl_setopt_array($request,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);}
            if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA')){curl_setopt($request,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);}
            $response=curl_exec($request);$status=curl_getinfo($request,CURLINFO_RESPONSE_CODE);
        }finally{curl_close($request);}
        $body=is_string($response)?json_decode($response,true):null;
        if($status!==200||!is_array($body)||isset($body['error'])){throw new \DomainException(self::INVALID);}
        return $body;
    }
}
