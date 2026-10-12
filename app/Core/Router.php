<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Tiny router: one route table, "/api/tokens/{public_id}" style patterns.
 *
 * Handler signature: fn(Request $request, array $params): Response|array|null
 *   - Response -> sent as-is
 *   - array    -> wrapped in the {"ok":true,"data":...} envelope
 *   - null     -> 204 No Content
 *
 * Optional middleware: fn(Request $request, array $params): ?Response
 *   - returning a Response short-circuits the request (used for staff auth).
 */
final class Router
{
    /**
     * @var list<array{
     *     method: string,
     *     pattern: string,
     *     regex: string,
     *     params: list<string>,
     *     handler: callable|array{class-string, string},
     *     middleware: list<callable>
     * }>
     */
    private array $routes = [];

    /** @param callable|array{class-string, string} $handler */
    public function add(
        string $method,
        string $pattern,
        callable|array $handler,
        array $middleware = [],
    ): void {
        [$regex, $params] = self::compile($pattern);

        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $pattern,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param callable|array{class-string, string} $handler */
    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param callable|array{class-string, string} $handler */
    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];
        $pathMatched    = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            // HEAD is served by the GET handler; the body is dropped in send().
            $methodMatches = $route['method'] === $request->method()
                || ($request->isMethod('HEAD') && $route['method'] === 'GET');

            if (!$methodMatches) {
                $allowedMethods[$route['method']] = true;

                continue;
            }

            $params = [];

            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? null;
            }

            return $this->run($route, $request, $params);
        }

        if ($pathMatched) {
            $allow = array_keys($allowedMethods);

            if ($request->isMethod('OPTIONS')) {
                return Response::noContent()->withHeader('Allow', implode(', ', $allow));
            }

            return Response::error('Method not allowed for this endpoint.', 405)
                ->withHeader('Allow', implode(', ', $allow));
        }

        return Response::error(sprintf('No route matches %s %s.', $request->method(), $request->path()), 404);
    }

    /**
     * @param array{handler: callable|array{class-string, string}, middleware: list<callable>, method: string, pattern: string} $route
     * @param array<string, string|null> $params
     */
    private function run(array $route, Request $request, array $params): Response
    {
        foreach ($route['middleware'] as $middleware) {
            $result = $middleware($request, $params);

            if ($result instanceof Response) {
                return $result;
            }
        }

        return self::normalise($this->invoke($route['handler'], $request, $params));
    }

    /**
     * @param callable|array{class-string, string} $handler
     * @param array<string, string|null> $params
     */
    private function invoke(callable|array $handler, Request $request, array $params): mixed
    {
        // [Controller::class, 'method'] — the controller is instantiated here;
        // this keeps controllers free of static state.
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            $controller = new $handler[0]();
            $method     = $handler[1];

            return $controller->{$method}($request, $params);
        }

        return $handler($request, $params);
    }

    private static function normalise(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::ok($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        if (is_scalar($result)) {
            return Response::text((string) $result);
        }

        return Response::json($result);
    }

    /**
     * "/api/tokens/{public_id}" -> "#^/api/tokens/(?P<public_id>[^/]+)$#"
     *
     * A pattern segment may carry a constraint: "{id:\d+}".
     *
     * @return array{string, list<string>}
     */
    private static function compile(string $pattern): array
    {
        $params  = [];
        $regex   = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $m) use (&$params): string {
                $name       = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                $params[]   = $name;

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );

        if ($regex === null) {
            throw new HttpException(sprintf('Route pattern could not be compiled: %s', $pattern), 500);
        }

        $regex = rtrim($regex, '/');
        $regex = $regex === '' ? '/' : $regex;

        return ['#^' . $regex . '$#', $params];
    }
}
