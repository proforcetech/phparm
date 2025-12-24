<?php

namespace App\Support\Http;

class Response
{
    private mixed $content;
    private int $statusCode;

    /**
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(mixed $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new self($content !== false ? $content : '', $status, ['Content-Type' => 'application/json']);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain']);
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html']);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function make(mixed $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function created(mixed $data = null): self
    {
        if ($data === null) {
            return new self('', 201);
        }
        return self::json($data, 201);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return self::json(['error' => $message], 404);
    }

    public static function badRequest(string $message = 'Bad Request'): self
    {
        return self::json(['error' => $message], 400);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::json(['error' => $message], 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::json(['error' => $message], 403);
    }

    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return self::json(['error' => $message], 500);
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if (is_string($this->content)) {
            echo $this->content;
        } elseif ($this->content !== null) {
            echo (string) $this->content;
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
