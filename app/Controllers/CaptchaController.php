<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\Captcha;
use Core\Controller;
use Core\Csrf;

final class CaptchaController extends Controller
{
    public function image(): void
    {
        try{Captcha::image(input_text($_GET,'id'));}
        catch(\DomainException $exception){
            http_response_code(410);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');
            echo __(Captcha::REQUIRED);
        }
    }

    public function refresh(): never
    {
        Csrf::ensure();header('Cache-Control: no-store');
        try{
            $id=Captcha::refresh(input_text($_POST,'captcha_id'));
            $this->json(true,'Đã đổi mã CAPTCHA.',['id'=>$id,'image_url'=>url('/captcha/image?id='.$id)]);
        }catch(\DomainException $exception){$this->json(false,$exception->getMessage(),null,[],422);}
    }
}
