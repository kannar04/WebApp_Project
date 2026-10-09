<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use PDO;

final class User
{
    private PDO $db;

    public function __construct() { $this->db = Database::connection(); }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        return $statement->fetch() ?: null;
    }

    public function findWithRoles(int $id): ?array
    {
        $statement = $this->db->prepare("SELECT u.*, GROUP_CONCAT(ur.role ORDER BY ur.role) AS role_list FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id WHERE u.id = ? GROUP BY u.id");
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        $user['roles'] = $user['role_list'] ? explode(',', $user['role_list']) : [];
        unset($user['password_hash'], $user['role_list']);
        return $user;
    }

    public function findForAdmin(int $id): ?array
    {
        $statement=$this->db->prepare("SELECT u.id,u.email,u.full_name,u.phone,u.status,GROUP_CONCAT(ur.role ORDER BY ur.role) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL GROUP BY u.id");
        $statement->execute([$id]);
        return $statement->fetch()?:null;
    }

    public function create(string $email, string $password, string $fullName, ?string $phone): int
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO users (email, password_hash, full_name, phone, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'active\', NOW(6), NOW(6))');
            $statement->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), trim($fullName), $phone ?: null]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare("INSERT INTO user_roles (user_id, role, granted_at) VALUES (?, 'guest', NOW(6))")->execute([$id]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateProfile(int $id, string $fullName, ?string $phone, ?string $avatarUrl): void
    {
        $statement = $this->db->prepare('UPDATE users SET full_name = ?, phone = ?, avatar_url = COALESCE(?, avatar_url), updated_at=NOW(6) WHERE id = ?');
        $statement->execute([trim($fullName), $phone ?: null, $avatarUrl, $id]);
    }

    public function grantHost(int $id): void
    {
        $this->db->prepare("INSERT IGNORE INTO user_roles (user_id, role, granted_at) VALUES (?, 'host', NOW(6))")->execute([$id]);
    }

    public function all(string $query = ''): array
    {
        $sql = "SELECT u.id, u.email, u.full_name, u.phone, u.status, u.created_at, GROUP_CONCAT(ur.role ORDER BY ur.role) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id";
        $params = [];
        if ($query !== '') {
            $sql .= ' WHERE (u.email LIKE ? OR u.full_name LIKE ?)';
            $params = ['%' . $query . '%', '%' . $query . '%'];
        }
        $sql .= ($query !== '' ? ' AND' : ' WHERE') . ' u.deleted_at IS NULL GROUP BY u.id ORDER BY u.created_at DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function adminCreate(array $data, array $roles): int
    {
        $id = $this->create($data['email'], $data['password'], $data['full_name'], $data['phone'] ?: null);
        $this->replaceRoles($id, $roles);
        return $id;
    }

    public function adminUpdate(int $id, array $data): void
    {
        $params = [mb_strtolower(trim($data['email'])), trim($data['full_name']), $data['phone'] ?: null];
        $sql = 'UPDATE users SET email=?,full_name=?,phone=?,updated_at=NOW(6)';
        if (!empty($data['password'])) {
            $sql .= ',password_hash=?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=? AND deleted_at IS NULL';
        $params[] = $id;
        $this->db->prepare($sql)->execute($params);
    }

    public function softDelete(int $id): void
    {
        $this->db->prepare("UPDATE users SET status='deleted',deleted_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([$id]);
    }

    public function replaceRoles(int $id, array $roles): void
    {
        $allowed = array_values(array_intersect(['guest', 'host', 'admin'], $roles));
        if (!$allowed) { $allowed = ['guest']; }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            $statement = $this->db->prepare('INSERT INTO user_roles (user_id, role, granted_at) VALUES (?, ?, NOW(6))');
            foreach ($allowed as $role) { $statement->execute([$id, $role]); }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}

