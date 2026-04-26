<?php

namespace App\Support\Http;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private string $rawBody;

    /**
     * @var array<string, mixed>
     */
    private array $query;

    /**
     * @var array<string, mixed>
     */
    private array $body;

    /**
     * @var array<string, mixed>
     */
    private array $files;

    /**
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     */
    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $server = [],
        array $files = [],
        string $rawBody = ''
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $this->rawBody = $rawBody;
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->server = $server;
        $this->files = $files;
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = (string) $value;
            }
        }

        // Handle Authorization header passed via Apache RewriteRule
        // Apache prefixes env vars set via E= with REDIRECT_
        if (!isset($headers['AUTHORIZATION'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        $rawBody = file_get_contents('php://input');
        $rawBody = $rawBody !== false ? $rawBody : '';
        $contentType = $headers['CONTENT-TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        } else {
            $body = $_POST;
        }

        return new self($method, $uri, $_GET, $body, $headers, $_SERVER, $_FILES, $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        // Headers are stored with dashes (e.g., X-CSRF-TOKEN), so normalize to match
        $normalized = strtoupper(str_replace('_', '-', $name));
        return $this->headers[$normalized] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('AUTHORIZATION');
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('CONTENT-TYPE') ?? '';
        return str_contains($contentType, 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function fullUrl(): string
    {
        $scheme = 'http';
        $https = $this->server['HTTPS'] ?? null;
        if ($https !== null && $https !== '' && strtolower((string) $https) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($this->server['REQUEST_SCHEME'])) {
            $scheme = (string) $this->server['REQUEST_SCHEME'];
        }

        $host = $this->header('HOST')
            ?? (isset($this->server['HTTP_HOST']) ? (string) $this->server['HTTP_HOST'] : null)
            ?? (isset($this->server['SERVER_NAME']) ? (string) $this->server['SERVER_NAME'] : null)
            ?? 'localhost';

        return $scheme . '://' . $host . $this->uri;
    }

    /**
     * Get the client's IP address
     * Checks for proxied requests via X-Forwarded-For header
     *
     * @return string|null
     */
    public function getClientIp(): ?string
    {
        return IpAddressResolver::resolve($this->server, $this->headers);
    }
}
