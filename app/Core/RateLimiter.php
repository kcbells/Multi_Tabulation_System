<?php
declare(strict_types=1);

namespace App\Core;

/** Throttles failed sign-in attempts per IP address (10-minute window). */
final class RateLimiter
{
    private Database $db;

    public function __construct(private string $ip)
    {
        $this->db = Database::instance();
    }

    public function ensureNotLocked(): void
    {
        $count = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 10 MINUTE)',
            [$this->ip]
        );
        if ($count >= (int) config('security.max_login_attempts', 10)) {
            throw new HttpException('Too many failed attempts. Please wait 10 minutes and try again.', 429);
        }
    }

    public function hit(): void
    {
        $this->db->execute('INSERT INTO login_attempts (ip) VALUES (?)', [$this->ip]);
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }

    public function clear(): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE ip = ?', [$this->ip]);
    }
}
