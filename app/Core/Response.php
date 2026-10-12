<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object plus the JSON envelope helpers.
 *
 * Success: {"ok": true,  "data": {...}}
 * Failure: {"ok": false, "error": "message", "details": {...}}
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json   = json_encode(['ok' => false, 'error' => 'Response could not be encoded.']) ?: '{}';
            $status = 500;
        }

        return new self($json, $status, array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
        ], $headers));
    }

    /**
     * The standard success envelope.
     *
     * @param array<string, mixed> $meta
     */
    public static function ok(mixed $data = null, int $status = 200, array $meta = []): self
    {
        $payload = ['ok' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    /**
     * The standard failure envelope.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $message, int $status = 400, array $details = []): self
    {
        $payload = ['ok' => false, 'error' => $message];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return self::json($payload, $status);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withoutCache(): self
    {
        $this->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
        $this->headers['Pragma']        = 'no-cache';

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Emit the response to the client. No output before this point.
     */
    public function send(): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
