<?php

namespace App\Support\Http;

class Route
{
    private string $method;
    private string $pattern;

    /**
     * @var callable
     */
    private $handler;

    /**
     * @var array<string, mixed>
     */
    private array $matches = [];

    /**
     * @var array<callable>
     */
    private array $middleware = [];

    private ?string $name = null;

    /**
     * @param callable $handler
     */
    public function __construct(string $method, string $pattern, callable $handler)
    {
        $this->method = strtoupper($method);
        $this->pattern = $pattern;
        $this->handler = $handler;
    }

    public function matches(string $method, string $path): bool
    {
        if ($this->method !== strtoupper($method)) {
            return false;
        }

        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $this->pattern);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $path, $matches)) {
            // Extract named parameters
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $this->matches[$key] = $value;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }

    /**
     * @return callable
     */
    public function getHandler(): callable
    {
        return $this->handler;
    }

    /**
     * @param callable $middleware
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * @return array<callable>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }
}
