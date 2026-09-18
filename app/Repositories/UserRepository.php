<?php
declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);
    }

    public function all(): array
    {
        return $this->db->all(
            'SELECT u.id, u.name, u.username, u.role, u.program, u.is_active, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM events e WHERE e.owner_id = u.id) AS events
             FROM users u ORDER BY u.role, u.name'
        );
    }

    public function activeOwners(): array
    {
        return $this->db->all('SELECT id, name, program, role FROM users WHERE is_active = 1 ORDER BY role, name');
    }

    public function usernameTaken(string $username, int $exceptId = 0): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $exceptId]);
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO users (name, username, password_hash, role, program, is_active) VALUES (?, ?, ?, ?, ?, ?)',
            [$d['name'], $d['username'], password_hash($d['password'], PASSWORD_DEFAULT), $d['role'], $d['program'], $d['is_active']]
        );
    }

    public function update(int $id, array $d): void
    {
        $this->db->execute(
            'UPDATE users SET name = ?, username = ?, role = ?, program = ?, is_active = ? WHERE id = ?',
            [$d['name'], $d['username'], $d['role'], $d['program'], $d['is_active'], $id]
        );
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->db->execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function countAdmins(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }
}
