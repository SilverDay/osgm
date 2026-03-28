<?php declare(strict_types=1);

/**
 * OSGridManager — Front Controller
 *
 * All web requests are routed through this file.
 * Config file path can be overridden via OGM_CONFIG env var (useful for dev).
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$appRoot = dirname(__DIR__);

// Core helpers — order matters (Logger → Config → DB → everything else)
require_once $appRoot . '/src/Core/Logger.php';
require_once $appRoot . '/src/Core/Config.php';
require_once $appRoot . '/src/Core/DB.php';
require_once $appRoot . '/src/Core/Validator.php';
require_once $appRoot . '/src/Core/Request.php';
require_once $appRoot . '/src/Core/Response.php';
require_once $appRoot . '/src/Core/Router.php';
require_once $appRoot . '/src/Core/RateLimit.php';
require_once $appRoot . '/src/Core/Session.php';
require_once $appRoot . '/src/Core/Csrf.php';
require_once $appRoot . '/src/Core/Auth.php';

// Allow config path override via env var (useful for testing/CI).
// Default is config/config.php relative to the project root (set in Config::load()).
$configPath = getenv('OGM_CONFIG');
if ($configPath !== false && $configPath !== '') {
    OGM\Core\Config::setConfigPath($configPath);
}

// Load static config (throws on missing file — let it propagate as 500)
OGM\Core\Config::load();

// Load runtime config from DB (non-fatal if DB unavailable during setup)
try {
    OGM\Core\Config::loadRuntime(OGM\Core\DB::getInstance());
} catch (\Throwable $e) {
    OGM\Core\Logger::error('Runtime config load failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Global helper: HTML output escaping
// ---------------------------------------------------------------------------

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// Request + Session
// ---------------------------------------------------------------------------

$request  = new OGM\Core\Request();
$response = new OGM\Core\Response();

OGM\Core\Session::start($request);

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$router = new OGM\Core\Router();

// -- Auth --
$router->get('/login',        [\OGM\Modules\User\UserController::class, 'showLogin']);
$router->post('/auth/login',  [\OGM\Modules\User\UserController::class, 'processLogin']);
$router->get('/auth/logout',  [\OGM\Modules\User\UserController::class, 'logout']);

// -- Home --
$router->get('/', [\OGM\Modules\User\UserController::class, 'home']);

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

// Load module controllers on demand (no Composer — explicit requires)
// Controllers are loaded lazily here; modules will add their own requires
// once implemented. For Phase 1, we load a placeholder UserController.

$userControllerPath = $appRoot . '/src/Modules/User/UserController.php';
if (file_exists($userControllerPath)) {
    require_once $userControllerPath;
}

$matched = false;
try {
    $matched = $router->dispatch($request, $response);
} catch (\Throwable $e) {
    OGM\Core\Logger::error('Unhandled exception: ' . $e->getMessage(), [
        'action' => 'dispatch_error',
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    http_response_code(500);
    $env = OGM\Core\Config::file('app.env', 'production');
    if ($env === 'development') {
        echo '<pre>' . h($e->getMessage()) . "\n" . h($e->getTraceAsString()) . '</pre>';
    } else {
        echo 'An internal error occurred. Please try again later.';
    }
    exit;
}

if (!$matched) {
    http_response_code(404);
    // Render a minimal 404 page; a proper template can be added later
    $gridName = OGM\Core\Config::get('grid_name', 'OSGridManager');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<title>404 Not Found &mdash; ' . h($gridName) . '</title></head>'
       . '<body><h1>404 Not Found</h1><p>The page you requested does not exist.</p>'
       . '<p><a href="/">Return to home</a></p></body></html>';
}
