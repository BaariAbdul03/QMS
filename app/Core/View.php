<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain PHP templates. No template engine, no compilation step — the views are
 * .php files rendered inside output buffering and wrapped in a layout.
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): Response
    {
        return Response::html(self::toHtml($view, $data, $layout));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toHtml(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($view, $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, $data + ['content' => $content]);
    }

    public static function exists(string $view): bool
    {
        return is_file(self::path($view));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function capture(string $view, array $data): string
    {
        $file = self::path($view);

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('View not found: %s (%s)', $view, $file));
        }

        $data = array_merge(self::$shared, $data);

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    private static function path(string $view): string
    {
        $directory = Config::string('paths.views', APP_PATH . '/Views');

        return $directory . '/' . str_replace(['.', '..'], '', $view) . '.php';
    }
}
