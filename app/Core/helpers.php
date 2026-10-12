<?php

declare(strict_types=1);

use App\Core\Config;

/*
|--------------------------------------------------------------------------
| View helpers
|--------------------------------------------------------------------------
| Loaded once by app/bootstrap.php. Deliberately tiny: escaping and asset URLs
| are the only things every template needs.
*/

if (!function_exists('e')) {
    /**
     * Escape for HTML text and attribute contexts. Views must never echo raw
     * user data (plan section 6, "Security checklist").
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /**
     * Absolute URL for a file in public/assets, honouring an optional base path
     * so the app also works from a subdirectory.
     */
    function asset(string $path): string
    {
        $base = rtrim(Config::string('app.base_path', ''), '/');

        return $base . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $base = rtrim(Config::string('app.base_path', ''), '/');

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('json_script')) {
    /**
     * JSON safe to embed in a <script type="application/json"> block.
     *
     * NOT htmlspecialchars(): escaping the structural quotes would corrupt the
     * JSON. The JSON_HEX_* flags instead neutralise every character that could
     * close the script element or be reinterpreted as markup, while the payload
     * stays valid JSON that JSON.parse() accepts as-is.
     */
    function json_script(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: 'null';
    }
}
