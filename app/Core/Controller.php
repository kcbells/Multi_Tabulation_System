<?php
declare(strict_types=1);

namespace App\Core;

/** Base class for API controllers. */
abstract class Controller
{
    public function __construct(protected Request $request)
    {
        // No database connection here: routes such as auth.me must still answer
        // (installed: false) before the database has been created.
    }

    protected function db(): Database
    {
        return Database::instance();
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException('Please sign in again.', 401);
    }

    protected function ok(array $data = [], ?string $message = null): never
    {
        if ($message !== null) {
            $data['message'] = $message;
        }
        Response::json(['ok' => true] + $data);
    }

    protected function fail(string $message, int $status = 422): never
    {
        throw new HttpException($message, $status);
    }
}
