<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Dispatches "resource.action" routes (see app/routes.php) to controller methods.
 * Each route declares its HTTP method and the roles allowed to call it
 * (an empty role list means public). POST requests always require a CSRF token.
 */
final class Router
{
    public function __construct(private array $routes) {}

    public function dispatch(Request $request): void
    {
        try {
            $name = (string) $request->get('r', '');
            $route = $this->routes[$name] ?? null;
            if ($route === null) {
                throw new HttpException('Unknown API route.', 404);
            }
            [$controllerClass, $method, $httpMethod, $roles] = $route;

            if ($request->method() !== $httpMethod) {
                throw new HttpException('Method not allowed.', 405);
            }
            if ($httpMethod === 'POST') {
                Csrf::verify($request);
            }
            if ($roles) {
                $user = Auth::user();
                if (!$user) {
                    throw new HttpException('Please sign in again.', 401);
                }
                if (!in_array($user['role'], $roles, true)) {
                    throw new HttpException('You do not have permission to do that.', 403);
                }
            }

            $controller = new $controllerClass($request);
            $controller->$method();
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->status());
        } catch (Throwable $e) {
            error_log('[tabulation] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::error(config('app.debug') ? 'Server error: ' . $e->getMessage() : 'Something went wrong on the server.', 500);
        }
    }
}
