<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;

final class IdentityDocumentService
{
    public const KINDS=['front','back','selfie'];
    private const MAX_BYTES=5*1024*1024;
    private const MAX_PIXELS=25000000;

    /** @return array{storage_name:string,mime_type:string,byte_size:int} */
    public function store(array $file,string $kind): array
    {
        if(!in_array($kind,self::KINDS,true)){throw new DomainException('Loại ảnh xác minh không hợp lệ.');}
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){throw new DomainException('Vui lòng tải đủ mặt trước, mặt sau CCCD và ảnh khuôn mặt.');}
        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])){throw new DomainException('Tải ảnh xác minh thất bại.');}
        $size=filesize($file['tmp_name']);
        if($size===false||$size<1||$size>self::MAX_BYTES){throw new DomainException('Mỗi ảnh xác minh phải nhỏ hơn 5 MB.');}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(!isset($extensions[$mime])){throw new DomainException('Ảnh xác minh chỉ chấp nhận JPG, PNG hoặc WebP.');}
        $info=@getimagesize($file['tmp_name']);
        if(!$info||($info[0]??0)<480||($info[1]??0)<320||$info[0]*$info[1]>self::MAX_PIXELS){throw new DomainException('Ảnh xác minh phải rõ, tối thiểu 480×320 px và không vượt quá 25 megapixel.');}
        $directory=self::directory();
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){throw new \RuntimeException('Không thể tạo kho ảnh xác minh riêng tư.');}
        $name=$kind.'-'.bin2hex(random_bytes(24)).'.'.$extensions[$mime];$target=$directory.DIRECTORY_SEPARATOR.$name;
        if(!move_uploaded_file($file['tmp_name'],$target)){throw new \RuntimeException('Không thể lưu ảnh xác minh.');}
        @chmod($target,0600);$stored=filesize($target);
        if($stored===false||$stored<1){@unlink($target);throw new \RuntimeException('Ảnh xác minh đã lưu không hợp lệ.');}
        return ['storage_name'=>$name,'mime_type'=>$mime,'byte_size'=>$stored];
    }

    public function discard(array $documents): void
    {
        foreach($documents as $document){$name=is_array($document)?($document['storage_name']??''):$document;if(is_string($name)&&preg_match('/^(?:front|back|selfie)-[a-f0-9]{48}\.(?:jpg|png|webp)$/D',$name)){@unlink(self::directory().DIRECTORY_SEPARATOR.$name);}}
    }

    public static function path(string $name): ?string
    {
        if(!preg_match('/^(?:front|back|selfie)-[a-f0-9]{48}\.(?:jpg|png|webp)$/D',$name)){return null;}$path=self::directory().DIRECTORY_SEPARATOR.$name;return is_file($path)?$path:null;
    }

    private static function directory(): string{return dirname(__DIR__,2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'identity-verifications';}
}
