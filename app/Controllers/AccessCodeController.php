<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\Request;
use App\Repositories\AccessCodeRepository;
use App\Services\ActivityLogger as Log;

/** Judge and facilitator access codes (no accounts needed). */
final class AccessCodeController extends Controller
{
    private AccessCodeRepository $codes;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->codes = new AccessCodeRepository();
    }

    private function authorizedCode(): array
    {
        $code = $this->codes->find($this->request->int('id')) ?? $this->fail('Access code not found.', 404);
        Gate::authorizeEvent((int) $code['event_id'], true);
        return $code;
    }

    public function save(): never
    {
        $id = $this->request->int('id');
        $name = $this->request->string('name', 150, true, 'Name');

        if ($id > 0) {
            $code = $this->authorizedCode();
            $eventId = (int) $code['event_id'];
            $role = $code['role'];
            $this->codes->rename($id, $name);
        } else {
            $eventId = $this->request->int('event_id');
            Gate::authorizeEvent($eventId, true);
            $role = $this->request->string('role', 20);
            if (!in_array($role, ['judge', 'facilitator'], true)) {
                $this->fail('Choose judge or facilitator.');
            }
            $id = $this->codes->create($eventId, $role, $name);
        }

        if ($role === 'judge') {
            $this->codes->syncActivities($id, $eventId, $this->request->array('activity_ids'));
        }
        Log::record(
            $this->request->int('id') > 0 ? 'code.updated' : 'code.issued',
            ($this->request->int('id') > 0 ? 'Updated ' : 'Issued an access code to ') . $role . ' ' . Log::q($name),
            $eventId
        );
        $saved = $this->codes->find($id);
        $this->ok(['id' => $id, 'code' => AccessCodeRepository::format($saved['code'])], ucfirst($role) . ' access saved.');
    }

    /** Generates several codes at once, e.g. "Judge 1" … "Judge 5", all assigned to the chosen activities. */
    public function bulk(): never
    {
        $eventId = $this->request->int('event_id');
        Gate::authorizeEvent($eventId, true);
        $role = $this->request->string('role', 20);
        if (!in_array($role, ['judge', 'facilitator'], true)) {
            $this->fail('Choose judge or facilitator.');
        }
        $count = $this->request->int('count');
        if ($count < 1 || $count > 50) {
            $this->fail('Generate between 1 and 50 codes at a time.');
        }
        $prefix = $this->request->string('prefix', 100) ?: ($role === 'judge' ? 'Judge' : 'Facilitator');

        // Continue numbering after existing "Judge 3" style names
        $next = 1;
        foreach ($this->codes->namesForRole($eventId, $role) as $existing) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\s+(\d+)$/iu', trim($existing), $m)) {
                $next = max($next, (int) $m[1] + 1);
            }
        }

        $activityIds = $this->request->array('activity_ids');
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $prefix . ' ' . ($next + $i);
            $id = $this->codes->create($eventId, $role, $name);
            if ($role === 'judge' && $activityIds) {
                $this->codes->syncActivities($id, $eventId, $activityIds);
            }
            $row = $this->codes->find($id);
            $created[] = ['id' => $id, 'name' => $name, 'code' => AccessCodeRepository::format($row['code'])];
        }

        Log::record('code.issued', 'Issued ' . $count . ' ' . $role . ' access codes (' . $created[0]['name'] . ($count > 1 ? ' – ' . end($created)['name'] : '') . ')', $eventId);
        $this->ok(['codes' => $created], $count . ' access code' . ($count === 1 ? '' : 's') . ' generated.');
    }

    public function regenerate(): never
    {
        $code = $this->authorizedCode();
        $new = $this->codes->regenerate((int) $code['id']);
        Log::record('code.regenerated', 'Replaced the access code of ' . $code['role'] . ' ' . Log::q($code['name']) . ' (old code disabled)', (int) $code['event_id']);
        $this->ok(['code' => AccessCodeRepository::format($new)], 'A new code was generated. The old code no longer works.');
    }

    public function toggle(): never
    {
        $code = $this->authorizedCode();
        $active = !$code['is_active'];
        $this->codes->setActive((int) $code['id'], $active);
        Log::record('code.' . ($active ? 'enabled' : 'disabled'), ($active ? 'Enabled' : 'Disabled') . ' the access code of ' . $code['role'] . ' ' . Log::q($code['name']), (int) $code['event_id']);
        $this->ok(['is_active' => $active], $active ? 'Code enabled.' : 'Code disabled — it can no longer sign in.');
    }

    public function delete(): never
    {
        $code = $this->authorizedCode();
        $this->codes->delete((int) $code['id']);
        Log::record('code.deleted', 'Deleted the access code of ' . $code['role'] . ' ' . Log::q($code['name']), (int) $code['event_id']);
        $this->ok([], 'Access code deleted.');
    }
}
