<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Repositories\AccessCodeRepository;
use App\Repositories\UserRepository;
use App\Services\ActivityLogger as Log;

final class AuthController extends Controller
{
    /**
     * Session info for the static HTML pages: who is signed in, the CSRF token
     * to send with POST requests, and whether the database is installed.
     */
    public function me(): never
    {
        $installed = true;
        $user = null;
        try {
            $user = Auth::user();
        } catch (\PDOException $e) {
            $installed = false;
        }
        Response::json([
            'ok' => true,
            'installed' => $installed,
            'csrf' => Csrf::token(),
            'app' => ['name' => config('app.name'), 'school' => config('app.school'), 'live_seconds' => config('app.live_seconds', 4)],
            'user' => $user ? [
                'id' => $user['id'], 'kind' => $user['kind'], 'role' => $user['role'], 'name' => $user['name'],
                'program' => $user['program'] ?? null, 'event_id' => $user['event_id'] ?? null,
            ] : null,
        ]);
    }

    public function staffLogin(): never
    {
        $limiter = new RateLimiter($this->request->ip());
        $limiter->ensureNotLocked();

        $username = strtolower($this->request->string('username', 60, true, 'Username'));
        $password = $this->request->raw('password');
        $users = new UserRepository();
        $user = $users->findByUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $limiter->hit();
            Log::record('auth.failed', 'Failed staff sign-in for username ' . Log::q($username), null, null, [], ['kind' => 'guest', 'name' => $username]);
            $this->fail('Incorrect username or password.', 401);
        }
        if (!$user['is_active']) {
            $this->fail('This account is deactivated. Contact the administrator.', 403);
        }
        $limiter->clear();
        Auth::loginStaff($user);
        $users->touchLogin((int) $user['id']);
        Log::record('auth.login', 'Signed in with a staff account');
        $this->ok(['redirect' => base_url('pages/dashboard.html')]);
    }

    public function codeLogin(): never
    {
        $limiter = new RateLimiter($this->request->ip());
        $limiter->ensureNotLocked();

        $codes = new AccessCodeRepository();
        $code = $codes->findByCode($this->request->string('code', 32, true, 'Access code'));
        if (!$code || !$code['is_active']) {
            $limiter->hit();
            Log::record('auth.failed', $code ? 'Sign-in attempt with a disabled access code' : 'Failed access code sign-in', $code ? (int) $code['event_id'] : null, null, [], ['kind' => 'guest', 'name' => $code['name'] ?? null]);
            $this->fail('That access code is not valid or has been disabled.', 401);
        }
        if (!empty($code['event_archived'])) {
            $limiter->hit();
            $this->fail('This event has been archived, so its access codes no longer work.', 401);
        }
        $limiter->clear();
        Auth::loginCode($code);
        $codes->touch((int) $code['id']);
        Log::record('auth.login', 'Signed in with an access code', (int) $code['event_id']);
        $this->ok(['redirect' => base_url('pages/dashboard.html')]);
    }

    public function logout(): never
    {
        if (Auth::user()) {
            Log::record('auth.logout', 'Signed out');
        }
        Auth::logout();
        $this->ok(['redirect' => base_url('index.html')]);
    }

    public function changePassword(): never
    {
        $users = new UserRepository();
        $user = $users->find(Auth::id());
        if (!$user || !password_verify($this->request->raw('current_password'), $user['password_hash'])) {
            $this->fail('Your current password is incorrect.');
        }
        $new = $this->request->raw('new_password');
        if (strlen($new) < 8) {
            $this->fail('The new password must be at least 8 characters.');
        }
        $users->setPassword((int) $user['id'], $new);
        Log::record('auth.password', 'Changed their password');
        $this->ok([], 'Password updated.');
    }
}
