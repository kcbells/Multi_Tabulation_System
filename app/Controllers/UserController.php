<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Repositories\UserRepository;
use App\Services\ActivityLogger as Log;

/** Admin-only management of staff accounts (admins and program heads). */
final class UserController extends Controller
{
    private UserRepository $users;

    public function __construct(\App\Core\Request $request)
    {
        parent::__construct($request);
        $this->users = new UserRepository();
    }

    public function index(): never
    {
        $this->ok(['users' => $this->users->all(), 'me' => Auth::id()]);
    }

    public function save(): never
    {
        $id = $this->request->int('id');
        $data = [
            'name' => $this->request->string('name', 120, true, 'Name'),
            'username' => strtolower($this->request->string('username', 60, true, 'Username')),
            'role' => in_array($this->request->get('role'), ['admin', 'program_head'], true) ? $this->request->get('role') : 'program_head',
            'program' => $this->request->string('program', 120) ?: null,
            'is_active' => $this->request->bool('is_active') ? 1 : 0,
        ];
        $password = $this->request->raw('password');

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $data['username'])) {
            $this->fail('Username must be 3–60 letters, numbers, dots, dashes or underscores.');
        }
        if ($this->users->usernameTaken($data['username'], $id)) {
            $this->fail('That username is already taken.');
        }
        if ($password !== '' && strlen($password) < 8) {
            $this->fail('Password must be at least 8 characters.');
        }
        if ($id === Auth::id() && ($data['role'] !== 'admin' || !$data['is_active'])) {
            $this->fail('You cannot remove your own admin access.');
        }

        if ($id > 0) {
            if (!$this->users->find($id)) {
                $this->fail('Account not found.', 404);
            }
            $this->users->update($id, $data);
            Log::record('user.updated', 'Updated staff account ' . Log::q($data['name']) . ' (' . $data['role'] . ($data['is_active'] ? '' : ', disabled') . ')');
            if ($password !== '') {
                $this->users->setPassword($id, $password);
            }
        } else {
            if ($password === '') {
                $this->fail('Set a password for the new account.');
            }
            $id = $this->users->create($data + ['password' => $password]);
            Log::record('user.created', 'Created staff account ' . Log::q($data['name']) . ' (' . $data['role'] . ')');
        }
        $this->ok(['id' => $id], 'Account saved.');
    }

    public function delete(): never
    {
        $id = $this->request->int('id');
        if ($id === Auth::id()) {
            $this->fail('You cannot delete your own account.');
        }
        $target = $this->users->find($id);
        $this->users->delete($id);
        Log::record('user.deleted', 'Deleted staff account ' . Log::q($target['name'] ?? ('#' . $id)));
        $this->ok([], 'Account deleted. Their events are kept and can be reassigned.');
    }
}
