<?php

declare(strict_types=1);

namespace Medico\Http;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string, mixed> */
    public array $query;
    /** @var array<string, mixed> */
    public array $body;
    /** @var array<string, string> */
    public array $headers;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->resolvePath();
        $this->query = $_GET;
        $this->headers = $this->parseHeaders();
        $this->body = $this->parseBody();
    }

    private function resolvePath(): string
    {
        // Prefer PATH_INFO: /api/index.php/auth/login
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (is_string($pathInfo) && $pathInfo !== '') {
            return rtrim($pathInfo, '/') ?: '/';
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = preg_replace('#^/api(?:/index\.php)?#', '', $uri) ?? $uri;
        $uri = preg_replace('#^/index\.php#', '', $uri) ?? $uri;

        return rtrim($uri, '/') ?: '/';
    }

    /** @return array<string, string> */
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $contentType = $this->headers['Content-Type'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if ($this->method === 'POST' || $this->method === 'PUT' || $this->method === 'PATCH') {
            return $_POST;
        }
        return [];
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['Authorization'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @param mixed $default @return mixed */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
