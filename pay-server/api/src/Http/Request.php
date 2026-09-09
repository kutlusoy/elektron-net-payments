<?php

namespace ElektronNet\Payments\PayServer\Http;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string, string> */
    public array $headers;
    /** @var array<string, mixed> */
    public array $body;
    /** @var array<string, string> route parameters, filled in by the router */
    public array $params = [];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    public function __construct(string $method, string $path, array $headers, array $body)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                $body = is_array($decoded) ? $decoded : [];
            }
        }

        return new self($method, $path, $headers, $body);
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        return trim(substr($header, 7));
    }
}
