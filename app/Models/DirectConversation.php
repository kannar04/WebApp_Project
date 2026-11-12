<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;
final class DirectConversation
{
    public function open(int $actor,int $listing): int
    {
        $s=ProcedureConnection::connection()->prepare('CALL LTP_sp_direct_open(?,?)');$s->execute([$actor,$listing]);return (int)$s->fetchColumn();
    }
    public function state(int $chat,int $actor): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL LTP_sp_direct_state(?,?)');$s->execute([$chat,$actor]);
        return $s->fetch() ?: throw new \DomainException('Không tìm thấy cuộc trò chuyện.');
    }
    public function thread(int $chat,int $actor): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL LTP_sp_direct_thread(?,?)');$s->execute([$chat,$actor]);return $s->fetchAll();
    }
    public function send(int $chat,int $actor,string $body): void
    {
        ProcedureConnection::connection()->prepare('CALL LTP_sp_direct_send(?,?,?)')->execute([$chat,$actor,$body]);
    }
    public function inbox(int $actor): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL LTP_sp_direct_inbox(?)');$s->execute([$actor]);return $s->fetchAll();
    }
}
