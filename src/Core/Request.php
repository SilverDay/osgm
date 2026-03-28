<?php declare(strict_types=1);

namespace OGM\Core;

class Request
{
    private string $method;
    private string $path;
    private array  $query;
    private array  $post;
    private array  $headers;
    private string $rawBody;
    private ?array $jsonBody = null;
    private bool   $jsonParsed = false;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path    = $this->parsePath();
        $this->query   = $_GET;
        $this->post    = $_POST;
        $this->rawBody = (string) file_get_contents('php://input');
        $this->headers = $this->parseHeaders();
    }

    // -------------------------------------------------------------------------

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Get a GET query parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a POST field.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Decode the JSON request body. Returns null if the body is not valid JSON.
     */
    public function getJson(): ?array
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            if ($this->rawBody !== '') {
                $decoded = json_decode($this->rawBody, true);
                $this->jsonBody = is_array($decoded) ? $decoded : null;
            }
        }
        return $this->jsonBody;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Get a request header value (case-insensitive).
     */
    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? null;
    }

    /**
     * Get the real client IP address, respecting X-Forwarded-For only for
     * trusted proxies defined in config.
     */
    public function getIp(): string
    {
        $remoteAddr    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trustedProxies = Config::file('trusted_proxies', []);

        if (in_array($remoteAddr, $trustedProxies, true)) {
            $forwarded = $this->getHeader('x-forwarded-for');
            if ($forwarded !== null) {
                $ips = array_map('trim', explode(',', $forwarded));
                if (filter_var($ips[0], FILTER_VALIDATE_IP)) {
                    return $ips[0];
                }
            }
        }

        return $remoteAddr;
    }

    public function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // -------------------------------------------------------------------------

    private function parsePath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return '/';
        }
        return '/' . ltrim($path, '/');
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}
