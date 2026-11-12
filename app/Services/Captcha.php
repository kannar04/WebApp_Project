<?php
declare(strict_types=1);
namespace App\Services;

final class Captcha
{
    public const REQUIRED='Mã CAPTCHA không đúng hoặc đã hết hạn. Vui lòng nhập mã mới.';
    public const UNAVAILABLE='Không thể tạo ảnh CAPTCHA lúc này. Vui lòng tải lại trang và thử lại.';
    public const ALPHABET='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH=6;
    private const TTL=300;
    private const LIMIT=32;

    /** Step 1–2: select random characters from the supplied alphabet. */
    public static function makeCaptcha(string $source,int $len): string
    {
        if($source===''||$len<1||$len>12){throw new \InvalidArgumentException('Invalid CAPTCHA alphabet or length.');}
        $captcha='';$last=strlen($source)-1;
        for($i=0;$i<$len;$i++){$captcha.=$source[random_int(0,$last)];}
        return $captcha;
    }

    /** Step 3: each form/tab gets its own challenge, kept only in this session. */
    public static function issue(string $action): string
    {
        if(!function_exists('imagecreatetruecolor')){throw new \DomainException(self::UNAVAILABLE);}
        foreach($_SESSION['_captchas']??[] as $id=>$entry){
            if(($entry['expires']??0)<=time()){unset($_SESSION['_captchas'][$id]);}
        }
        while(count($_SESSION['_captchas']??[])>=self::LIMIT){unset($_SESSION['_captchas'][array_key_first($_SESSION['_captchas'])]);}
        $id=bin2hex(random_bytes(16));
        $alphabet=self::ALPHABET;
        $captcha=self::makeCaptcha($alphabet,self::LENGTH);
        $_SESSION['_captchas'][$id]=['captcha'=>$captcha,'action'=>$action,'expires'=>time()+self::TTL];
        return $id;
    }

    /** Refresh only an existing session-owned challenge; the old answer stops working. */
    public static function refresh(string $id): string
    {
        $entry=self::entry($id,false);
        if($entry===null){throw new \DomainException(self::REQUIRED);}
        unset($_SESSION['_captchas'][$id]);
        return self::issue($entry['action']);
    }

    public static function image(string $id): void
    {
        $entry=self::entry($id);
        if($entry===null){throw new \DomainException(self::REQUIRED);}
        self::setPNGHeader();
        self::makePNGCaptcha($entry['captcha']);
    }

    /** Step 4: emit a non-cacheable PNG, with no answer in its URL or HTML. */
    public static function setPNGHeader(): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    public static function makePNGCaptcha(string $captcha): void
    {
        if(!function_exists('imagecreatetruecolor')){throw new \DomainException(self::UNAVAILABLE);}
        // Render at 3x resolution, then downsample for smooth letters and curves.
        $width=660;$height=216;
        $image=imagecreatetruecolor($width,$height);
        $background=imagecolorallocate($image,242,246,235);
        imagefill($image,0,0,$background);
        $font=null;
        foreach([dirname(__DIR__,2).'/resources/fonts/captcha.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf'] as $candidate){
            if(is_readable($candidate)&&function_exists('imagettftext')){$font=$candidate;break;}
        }
        for($i=0;$i<strlen($captcha);$i++){
            $ink=imagecolorallocate($image,random_int(25,65),random_int(55,100),random_int(40,85));
            $angle=random_int(-28,28);
            if($font!==null){
                imagettftext($image,random_int(90,105),$angle,45+$i*94+random_int(-6,6),random_int(137,171),$ink,$font,$captcha[$i]);
            }else{
                // Portable fallback if the host has no TrueType font installed.
                $glyph=imagecreatetruecolor(14,23);
                imagefill($glyph,0,0,imagecolorallocate($glyph,242,246,235));
                imagestring($glyph,5,2,4,$captcha[$i],$ink);
                $large=imagecreatetruecolor(88,138);
                imagecopyresized($large,$glyph,0,0,0,0,88,138,14,23);
                $rotated=imagerotate($large,$angle,$background);
                imagecopy($image,$rotated,40+$i*94,random_int(25,45),0,0,imagesx($rotated),imagesy($rotated));
                imagedestroy($glyph);imagedestroy($large);imagedestroy($rotated);
            }
        }
        // Bend the text baseline instead of leaving characters in an OCR-friendly row.
        $warped=imagecreatetruecolor($width,$height);
        imagefill($warped,0,0,$background);
        $phase=random_int(0,628)/100;
        for($x=0;$x<$width;$x++){
            $offset=(int)round(18*sin($x/57+$phase)+5*sin($x/19+$phase));
            imagecopy($warped,$image,$x,$offset,$x,0,1,$height);
        }
        imagedestroy($image);
        // Distort horizontally as well, bending individual strokes rather than only the baseline.
        $distorted=imagecreatetruecolor($width,$height);
        imagefill($distorted,0,0,$background);
        $phase=random_int(0,628)/100;
        for($y=0;$y<$height;$y++){
            $offset=(int)round(12*sin($y/24+$phase));
            imagecopy($distorted,$warped,$offset,$y,0,$y,$width,1);
        }
        imagedestroy($warped);$warped=$distorted;
        // Same-weight foreground curves merge with the glyphs; pale cuts interrupt strokes.
        for($curve=0;$curve<4;$curve++){
            $cut=$curve===3;
            $color=$cut?imagecolorallocate($warped,242,246,235)
                :imagecolorallocate($warped,random_int(35,85),random_int(65,110),random_int(45,90));
            imagesetthickness($warped,$cut?2:random_int(3,4));
            $center=65+($curve%3)*36;$phase=random_int(0,628)/100;
            $amplitude=random_int(22,40);$period=random_int(55,95);
            $previous=(int)($center+$amplitude*sin($phase));
            for($x=1;$x<$width;$x++){
                $y=(int)($center+$amplitude*sin($x/$period+$phase));
                imageline($warped,$x-1,$previous,$x,$y,$color);$previous=$y;
            }
        }
        imagesetthickness($warped,3);
        for($i=0;$i<7;$i++){
            $color=imagecolorallocate($warped,random_int(75,140),random_int(100,155),random_int(80,135));
            imagearc($warped,random_int(0,$width-1),random_int(20,$height-20),random_int(18,45),random_int(22,60),random_int(0,90),random_int(180,300),$color);
        }
        for($i=0;$i<280;$i++){
            $color=imagecolorallocate($warped,random_int(75,170),random_int(100,185),random_int(80,165));
            imagefilledellipse($warped,random_int(0,$width-1),random_int(0,$height-1),random_int(2,6),random_int(2,6),$color);
        }
        $output=imagecreatetruecolor(220,72);
        imagecopyresampled($output,$warped,0,0,0,0,220,72,$width,$height);
        imagepng($output);
        imagedestroy($warped);imagedestroy($output);
    }

    /** Step 5: consume on every attempt, including a wrong answer or action. */
    public static function ensure(string $action): void
    {
        $id=$_POST['captcha_id']??null;$answer=$_POST['captcha_answer']??null;
        if(!is_string($id)){throw new \DomainException(self::REQUIRED);}
        $entry=self::entry($id);
        unset($_SESSION['_captchas'][$id]);
        if($entry===null||$entry['action']!==$action||!is_string($answer)||strlen($answer)>32
            ||!hash_equals($entry['captcha'],strtoupper(trim($answer)))){throw new \DomainException(self::REQUIRED);}
    }

    private static function entry(string $id,bool $checkExpiry=true): ?array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)){return null;}
        $entry=$_SESSION['_captchas'][$id]??null;
        return is_array($entry)&&(!$checkExpiry||($entry['expires']??0)>time())?$entry:null;
    }
}
