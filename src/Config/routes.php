<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Account\DashboardController;
use App\Http\Controllers\Web\Account\SessionController;
use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\TwoFactorController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

/**
 * Route registration. Handlers are [Controller::class, 'method'] or closures.
 * Per-route middleware use aliases: auth, guest, can:<perm>, mfa, throttle:<n,min>.
 * Global middleware (request id, security headers, session, CSRF) are applied
 * by the Kernel to every request.
 */
return static function (Router $router): void {
    // ── Storefront ────────────────────────────────────────────────
    $router->get('/', [HomeController::class, 'index']);

    // ── Health / readiness probes (Req 15.4) ──────────────────────
    $router->get('/healthz', [HealthController::class, 'live']);
    $router->get('/readyz', [HealthController::class, 'ready']);

    // ── Guest-only auth (Req 2) ───────────────────────────────────
    $router->get('/register', [RegisterController::class, 'show'], ['guest']);
    $router->post('/register', [RegisterController::class, 'store'], ['guest', 'throttle:10,1']);

    $router->get('/login', [LoginController::class, 'show'], ['guest']);
    $router->post('/login', [LoginController::class, 'store'], ['guest', 'throttle:10,1']);

    $router->get('/forgot-password', [PasswordResetController::class, 'showForgot'], ['guest']);
    $router->post('/forgot-password', [PasswordResetController::class, 'sendResetLink'], ['guest', 'throttle:5,1']);
    $router->get('/reset-password', [PasswordResetController::class, 'showReset'], ['guest']);
    $router->post('/reset-password', [PasswordResetController::class, 'reset'], ['guest', 'throttle:5,1']);

    // ── Email verification (Req 2.3) ──────────────────────────────
    $router->get('/verify-email', [EmailVerificationController::class, 'verify']);

    // ── Two-factor login challenge (pending session, Req 2.4) ─────
    $router->get('/2fa', [TwoFactorController::class, 'challenge']);
    $router->post('/2fa', [TwoFactorController::class, 'verify'], ['throttle:10,1']);

    // ── Authenticated account area ────────────────────────────────
    $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
    $router->post('/logout', [LoginController::class, 'logout'], ['auth']);

    $router->get('/2fa/setup', [TwoFactorController::class, 'setup'], ['auth']);
    $router->post('/2fa/confirm', [TwoFactorController::class, 'confirm'], ['auth']);
    $router->post('/2fa/disable', [TwoFactorController::class, 'disable'], ['auth']);

    $router->get('/account/sessions', [SessionController::class, 'index'], ['auth']);
    $router->post('/account/sessions/revoke', [SessionController::class, 'revoke'], ['auth']);
    $router->post('/account/sessions/revoke-others', [SessionController::class, 'revokeOthers'], ['auth']);

    // ── API version banner (surface expands in Phase 10) ──────────
    $router->get('/api/v1/ping', static fn (Request $r): Response =>
        Response::json([
            'data' => ['pong' => true, 'version' => 'v1'],
            'meta' => ['request_id' => $r->attribute('request_id')],
        ]));
};
