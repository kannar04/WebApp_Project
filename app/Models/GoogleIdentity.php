<?php
declare(strict_types=1);
namespace App\Models;

use Core\ProcedureConnection;

final class GoogleIdentity
{
    private ProcedureConnection $db;
    public function __construct() { $this->db=ProcedureConnection::connection(); }

    public function find(string $subject): ?int
    {
        $query=$this->db->prepare('CALL NMT_sp_oauth_identity_find(?)');
        $query->execute([$subject]);
        $row=$query->fetch();
        return $row ? (int)$row['user_id'] : null;
    }

    public function linked(int $userId): bool
    {
        $query=$this->db->prepare('CALL LTP_sp_oauth_identity_status(?)');
        $query->execute([$userId]);
        return $query->fetch()!==false;
    }

    public function link(int $userId,string $subject): void
    {
        $this->validateSubject($subject);
        try { $this->db->prepare('CALL DST_sp_oauth_identity_link(?,?)')->execute([$userId,$subject]); }
        catch(\PDOException $exception) {
            if($exception->getCode()==='23000'){throw new \DomainException('Tài khoản Google hoặc tài khoản Home2Home đã có liên kết khác.');}
            throw $exception;
        }
    }

    /** Email equality never links an existing account. Only a verified provider subject signs it in. */
    public function resolve(array $identity): int
    {
        $this->validateSubject($identity['subject']);
        $known=$this->find($identity['subject']);
        if($known!==null){return $known;}
        $email=$identity['email'];
        if(!is_string($email)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)){
            throw new \DomainException('Google chưa cung cấp email đã xác minh. Vui lòng dùng email và mật khẩu.');
        }
        $users=new User();
        if($users->findByEmail($email)!==null){throw new \DomainException('Email này đã có tài khoản. Hãy đăng nhập bằng mật khẩu rồi liên kết Google trong hồ sơ.');}
        $ownsTransaction=!$this->db->inTransaction();
        if($ownsTransaction){$this->db->beginTransaction();}
        try {
            // No usable local password is derived from provider data. Password reset can set one later.
            $id=$users->create($email,bin2hex(random_bytes(32)),$identity['name'],null);
            $this->link($id,$identity['subject']);
            if($ownsTransaction){$this->db->commit();}
            return $id;
        } catch(\Throwable $exception) {
            if($ownsTransaction&&$this->db->inTransaction()){$this->db->rollBack();}
            if($exception instanceof \PDOException && $exception->getCode()==='23000'){
                $known=$this->find($identity['subject']);
                if($known!==null){return $known;}
                throw new \DomainException('Email này đã có tài khoản. Hãy đăng nhập bằng mật khẩu rồi liên kết Google trong hồ sơ.');
            }
            throw $exception;
        }
    }

    private function validateSubject(string $subject): void
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$subject)){throw new \DomainException('Không thể xác thực tài khoản Google. Vui lòng thử lại.');}
    }
}
