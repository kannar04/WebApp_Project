<?php

declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;

final class User
{
    private \Core\ProcedureConnection $db;

    public function __construct() { $this->db = Database::connection(); }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('CALL `sp_user_get_by_email`(?)');
        $statement->execute([mb_strtolower(trim($email))]);
        return $statement->fetch() ?: null;
    }

    public function findWithRoles(int $id): ?array
    {
        $statement = $this->db->prepare('CALL `sp_user_get_with_roles`(?)');
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        $user['roles'] = $user['role_list'] ? explode(',', $user['role_list']) : [];
        $user['auth_version']=hash('sha256',$user['password_hash']);
        unset($user['password_hash'], $user['role_list']);
        return $user;
    }

    public function findForAdmin(int $id): ?array
    {
        $statement=$this->db->prepare('CALL `sp_user_get_for_admin`(?)');
        $statement->execute([$id]);
        return $statement->fetch()?:null;
    }

    public function create(string $email, string $password, string $fullName, ?string $phone): int
    {
        $statement = $this->db->prepare('CALL sp_user_insert(?,?,?,?)');
        $statement->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), trim($fullName), $phone ?: null]);
        return (int) $this->db->lastInsertId();
    }

    public function updateProfile(int $id, string $fullName, ?string $phone, ?string $avatarUrl): void
    {
        $statement = $this->db->prepare('CALL `sp_user_update_profile`(?,?,?,?)');
        $statement->execute([trim($fullName), $phone ?: null, $avatarUrl, $id]);
    }

    public function grantHost(int $id): void
    {
        $this->db->prepare('CALL `sp_user_grant_host`(?)')->execute([$id]);
    }

    /** True while the last Admin change to this account's Host role was a removal. */
    public function hostRoleRemoved(int $id): bool
    {
        $statement = $this->db->prepare('CALL `sp_user_host_role_removed`(?)');
        $statement->execute([$id]);
        return (bool) $statement->fetchColumn();
    }

    public function all(string $query = ''): array
    {
        $statement = $this->db->prepare('CALL sp_user_search(?)');
        $statement->execute([$query]);
        return $statement->fetchAll();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->prepare('CALL `sp_user_set_status`(?,?)')->execute([$status, $id]);
    }

    public function adminCreate(array $data, array $roles): int
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) { $this->db->beginTransaction(); }
        try {
            $id = $this->create($data['email'], $data['password'], $data['full_name'], $data['phone'] ?: null);
            $this->replaceRoles($id, $roles);
            if ($ownsTransaction) { $this->db->commit(); }
            return $id;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }

    public function adminUpdate(int $id, array $data): void
    {
        $statement = $this->db->prepare('CALL sp_user_admin_update(?,?,?,?,?)');
        $statement->execute([mb_strtolower(trim($data['email'])), trim($data['full_name']), $data['phone'] ?: null,
            !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null, $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->prepare('CALL `sp_user_soft_delete`(?)')->execute([$id]);
    }

    /** $adminId names the Admin making the change, so a change to the Host role is recorded; fixtures and account creation pass none. */
    public function replaceRoles(int $id, array $roles, ?int $adminId = null): void
    {
        $allowed = array_values(array_intersect(['guest', 'host', 'admin'], $roles));
        $statement = $this->db->prepare('CALL sp_user_roles_replace(?,?,?)');
        $statement->execute([$id, json_encode($allowed ?: ['guest'], JSON_THROW_ON_ERROR), $adminId]);
    }

    public function changePassword(int $id,string $email,string $current,string $new): void
    {
        $user=$this->findByEmail($email);
        if (!$user || (int)$user['id']!==$id || $user['status']!=='active' || !password_verify($current,$user['password_hash'])) {
            throw new \DomainException('Mật khẩu hiện tại không đúng.');
        }
        $problem = \Core\Validator::passwordProblem($new);
        if ($problem !== null) { throw new \DomainException($problem); }
        if ($current===$new) { throw new \DomainException('Mật khẩu mới phải khác mật khẩu cũ.'); }
        $this->db->prepare('CALL sp_user_password_change(?,?,?)')->execute([$id,$user['password_hash'],password_hash($new,PASSWORD_DEFAULT)]);
    }

}

