<?php
declare(strict_types=1);

namespace App\Core;

/** Normalized access to query string, form fields, JSON bodies and uploads. */
final class Request
{
    private array $data;

    public function __construct()
    {
        $data = $_GET;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($body)) {
                $data = array_merge($data, $body);
            }
        } else {
            $data = array_merge($data, $_POST);
        }
        $this->data = $data;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /** Trimmed string; throws 422 when required and empty. */
    public function string(string $key, int $max = 255, bool $required = false, string $label = ''): string
    {
        $v = $this->data[$key] ?? '';
        $v = is_scalar($v) ? trim((string) $v) : '';
        if ($required && $v === '') {
            throw new HttpException(($label ?: ucfirst(str_replace('_', ' ', $key))) . ' is required.', 422);
        }
        return mb_substr($v, 0, $max);
    }

    /** Raw (untrimmed) string, e.g. passwords. */
    public function raw(string $key): string
    {
        $v = $this->data[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->data[$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $v = $this->data[$key] ?? null;
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->data[$key] ?? false;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function array(string $key): array
    {
        $v = $this->data[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        return is_array($f) && isset($f['tmp_name']) && !is_array($f['tmp_name']) ? $f : null;
    }
}
