<?php
declare(strict_types=1);
namespace App\Services;
use DomainException;
final class UploadService
{
    public function image(array $file,string $prefix): ?string
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){return null;}
        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK){throw new DomainException('Tải ảnh thất bại.');}
        $config=require dirname(__DIR__,2).'/config/app.php';
        if(($file['size']??0)>$config['upload_max_bytes']){throw new DomainException('Ảnh vượt quá giới hạn 5 MB.');}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(!isset($extensions[$mime])){throw new DomainException('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.');}
        $name=$prefix.'-'.bin2hex(random_bytes(12)).'.'.$extensions[$mime];$target=$config['upload_path'].DIRECTORY_SEPARATOR.$name;
        if(!move_uploaded_file($file['tmp_name'],$target)){throw new DomainException('Không thể lưu ảnh đã tải lên.');}
        return '/uploads/'.$name;
    }
}

