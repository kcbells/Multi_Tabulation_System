<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\AccessCodeRepository;
use App\Repositories\UserRepository;

/**
 * The signed-in principal.
 *   Staff accounts:   admin, program_head   (username + password)
 *   Access codes:     judge, facilitator     (no account, code only)
 */
final class Auth
{
    public const STAFF = ['admin', 'program_head'];
    public const MANAGERS = ['admin', 'program_head', 'facilitator'];

    private static ?array $user = null;
    private static bool $validated = false;

    /** @return array{kind:string,id:int,role:string,name:string,event_id?:int,program?:?string}|null */
    public static function user(): ?array
    {
        if (self::$validated) {
            return self::$user;
        }
        self::$validated = true;
        $a = Session::get('auth');
        if (!is_array($a)) {
            return self::$user = null;
        }
        // Re-validate every request so disabled accounts/codes lose access immediately.
        if ($a['kind'] === 'staff') {
            $row = (new UserRepository())->find((int) $a['id']);
            if (!$row || !$row['is_active']) {
                Session::forget('auth');
                return self::$user = null;
            }
            $a['role'] = $row['role'];
            $a['name'] = $row['name'];
            $a['program'] = $row['program'];
        } else {
            $row = (new AccessCodeRepository())->find((int) $a['id']);
            if (!$row || !$row['is_active'] || (new \App\Repositories\EventRepository())->isArchived((int) $row['event_id'])) {
                Session::forget('auth');
                return self::$user = null;
            }
            $a['name'] = $row['name'];
            $a['event_id'] = (int) $row['event_id'];
        }
        Session::set('auth', $a);
        return self::$user = $a;
    }

    public static function loginStaff(array $user): void
    {
        Session::regenerate();
        Session::set('auth', [
            'kind' => 'staff', 'id' => (int) $user['id'], 'role' => $user['role'],
            'name' => $user['name'], 'program' => $user['program'],
        ]);
        self::$validated = false;
    }

    public static function loginCode(array $code): void
    {
        Session::regenerate();
        Session::set('auth', [
            'kind' => 'code', 'id' => (int) $code['id'], 'role' => $code['role'],
            'name' => $code['name'], 'event_id' => (int) $code['event_id'],
        ]);
        self::$validated = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$validated = true;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function isStaff(): bool
    {
        return (self::user()['kind'] ?? null) === 'staff';
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    /** For page scripts: redirects to sign-in or dashboard when the role is not allowed. */
    public static function requirePage(array $roles): array
    {
        $user = self::user();
        if (!$user) {
            Response::redirect(base_url('index.html'));
        }
        if (!in_array($user['role'], $roles, true)) {
            Response::redirect(base_url('pages/dashboard.html'));
        }
        return $user;
    }
}
