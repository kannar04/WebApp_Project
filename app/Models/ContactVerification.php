<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;
final class ContactVerification
{
    public function challenge(string $id, ?int $actor): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL NMT_sp_email_challenge_get(?,?)'); $s->execute([$id,$actor]);
        return $s->fetch() ?: throw new \DomainException('Phiên xác minh đã hết hạn. Vui lòng đăng ký hoặc yêu cầu mã mới từ hồ sơ.');
    }
    public function status(int $user): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL NMT_sp_contact_status(?)'); $s->execute([$user]);
        $status=$s->fetch() ?: [];
        if ($status && \App\Services\DebugVerification::verified('phone',$user)
            && Trust::normalizePhone((string)$status['phone'])===($_SESSION['_debug_verification']['phone']??null)) {
            $status['email_verified']=1;
            $status['phone_verified']=1;
        }
        return $status;
    }
    public function issue(string $id, ?int $user, array $data, string $hash): void
    {
        ProcedureConnection::connection()->prepare('CALL NMT_sp_email_challenge_issue(?,?,?,?,?,?,?)')
            ->execute([$id,$user,$data['email'],$data['full_name'],$data['password_hash']??null,$data['phone']??null,$hash]);
    }
    public function consume(string $id, string $hash, ?int $actor): int
    {
        $s=ProcedureConnection::connection()->prepare('CALL NMT_sp_email_challenge_consume(?,?,?)'); $s->execute([$id,$hash,$actor]);
        $rows=$s->fetchAll();
        // Legacy insert routines may emit a metadata row; the final row is the challenge result.
        return (int)(end($rows)['user_id']??0);
    }
    public function phoneVerified(int $user, string $phone): void
    {
        ProcedureConnection::connection()->prepare('CALL NMT_sp_phone_verify(?,?)')->execute([$user,$phone]);
    }
    public function ensureBookable(int $user): void
    {
        $state=$this->status($user);
        if (empty($state['email_verified'])) { throw new \DomainException('Bạn chưa xác minh email. Mở hồ sơ để xác minh trước khi đặt phòng.'); }
        if (empty($state['phone_verified'])) { throw new \DomainException('Bạn chưa xác minh số điện thoại. Mở hồ sơ để nhận mã SMS trước khi đặt phòng.'); }
    }
}
