<?php
declare(strict_types=1);
namespace App\Services;
use DomainException;
final class UploadService
{
    /** Accept a PHP multiple-file field; remove newly written files if any upload fails. */
    public function images(array $files, string $prefix): array
    {
        $errors = $files['error'] ?? [];
        if (!is_array($errors) || count($errors) > 20) { throw new DomainException('Mỗi lần được tải tối đa 20 ảnh.'); }
        $saved = [];
        try {
            foreach ($errors as $index => $error) {
                $file = [];
                foreach (['name','tmp_name','size','error'] as $key) {
                    $value = $files[$key][$index] ?? null;
                    if (!is_scalar($value)) { throw new DomainException('Danh sách ảnh không hợp lệ.'); }
                    $file[$key] = $value;
                }
                $path = $this->image($file, $prefix);
                if ($path !== null) { $saved[] = $path; }
            }
            return $saved;
        } catch (\Throwable $exception) {
            $this->discard($saved);
            throw $exception;
        }
    }

    public function discard(array $paths): void
    {
        $config = require dirname(__DIR__,2).'/config/app.php';
        foreach ($paths as $path) {
            if (preg_match('#^/uploads/[a-zA-Z0-9-]+\.(jpg|png|webp)$#D', $path)) {
                $file = $config['upload_path'].DIRECTORY_SEPARATOR.basename($path);
                if (is_file($file)) { unlink($file); }
            }
        }
    }

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

