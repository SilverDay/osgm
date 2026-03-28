<?php declare(strict_types=1);

namespace OGM\Core;

class Response
{
    private int    $status  = 200;
    private array  $headers = [];
    private string $body    = '';

    public function __construct()
    {
        // Apply mandatory security headers by default
        $this->header('X-Content-Type-Options', 'nosniff');
        $this->header('X-Frame-Options', 'DENY');
        $this->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }

    public function status(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function body(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Send an HTML response using a template.
     */
    public function html(string $templatePath, array $vars = []): void
    {
        $this->header('Content-Type', 'text/html; charset=UTF-8');
        $this->header(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: https:; frame-ancestors 'none'"
        );

        ob_start();
        extract($vars, EXTR_SKIP);
        require $templatePath;
        $this->body = (string) ob_get_clean();

        $this->send();
    }

    /**
     * Send a JSON response.
     */
    public function json(array $data, int $status = 200): void
    {
        $this->status = $status;
        $this->header('Content-Type', 'application/json');
        $this->body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->send();
    }

    /**
     * Send a redirect response.
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->status = $status;
        $this->header('Location', $url);
        $this->send();
    }

    /**
     * Flush all headers and body to the client.
     */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }

    // -------------------------------------------------------------------------

    /**
     * Convenience: emit a standard JSON error and exit.
     */
    public static function jsonError(
        string $errorCode,
        string $message,
        int    $status  = 400,
        array  $details = []
    ): void {
        $payload = ['success' => false, 'error' => $errorCode, 'message' => $message];
        if ($details !== []) {
            $payload['details'] = $details;
        }
        (new self())->json($payload, $status);
        exit;
    }
}
