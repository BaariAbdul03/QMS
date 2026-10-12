<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view of the incoming request.
 *
 * Create one with Request::fromGlobals() in the front controller, or with
 * Request::create() from tests and CLI tools (no superglobals involved).
 */
final class Request
{
    private ?array $parsedBody = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $cookies,
        private readonly ?string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalisePath(is_string($path) ? $path : '/'),
            $_GET,
            $_POST,
            $_SERVER,
            array_map(static fn ($value): string => (string) $value, $_COOKIE),
            self::readRawBody(),
        );
    }

    /**
     * Test/CLI constructor. $rawBody may be a JSON string or a plain string.
     *
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        ?string $rawBody = null,
        array $server = [],
        array $cookies = [],
    ): self {
        $server['REQUEST_METHOD'] = strtoupper($method);
        $server['REQUEST_URI']    = $path;

        return new self(
            strtoupper($method),
            self::normalisePath(parse_url($path, PHP_URL_PATH) ?: '/'),
            $query,
            [],
            $server,
            $cookies,
            $rawBody,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * JSON body (decoded) merged over classic form fields, so handlers can call
     * input() without caring how the client sent the data.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        $decoded = [];

        if ($this->rawBody !== null && $this->rawBody !== '') {
            $contentType = strtolower((string) $this->header('content-type', ''));

            if (str_contains($contentType, 'json') || self::looksLikeJson($this->rawBody)) {
                $json = json_decode($this->rawBody, true);

                if (is_array($json)) {
                    $decoded = $json;
                }
            } elseif (str_contains($contentType, 'x-www-form-urlencoded')) {
                // PHP normally fills $_POST for this content type, but parsing
                // the raw body as well keeps this object self-contained — which
                // is what makes form posts testable without a web server.
                parse_str($this->rawBody, $decoded);
            }
        }

        return $this->parsedBody = array_merge($this->post, $decoded);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        return $this->query;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }

        // CONTENT_TYPE / CONTENT_LENGTH are not HTTP_-prefixed.
        $plain = strtoupper(str_replace('-', '_', $name));

        if (isset($this->server[$plain])) {
            return (string) $this->server[$plain];
        }

        return $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->server;
    }

    /**
     * @param array<string, mixed> $server
     */
    public function withServer(array $server): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->post,
            array_merge($this->server, $server),
            $this->cookies,
            $this->rawBody,
        );
    }

    private static function normalisePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function readRawBody(): ?string
    {
        $body = file_get_contents('php://input');

        return $body === false || $body === '' ? null : $body;
    }

    private static function looksLikeJson(string $body): bool
    {
        $first = substr(ltrim($body), 0, 1);

        return $first === '{' || $first === '[';
    }
}
