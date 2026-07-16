<?php

declare(strict_types=1);

/**
 * Phase 10 tests: API-key issuance/verification/scopes/expiry, outbound
 * webhook subscription + signed fan-out, the JSON envelope, and an OpenAPI
 * contract test asserting every documented path is a registered route.
 * In-memory + no DB. Run: php tests/phase10.php
 */

use App\Application\Api\ApiKeyService;
use App\Application\Api\WebhookService;
use App\Bootstrap\App;
use App\Domain\Api\ApiScope;
use App\Domain\Api\WebhookEvent;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Request;
use App\Infrastructure\Queue\ArrayQueueDriver;
use App\Infrastructure\Queue\Dispatcher;
use Tests\Fakes\InMemoryApiKeyRepository;
use Tests\Fakes\InMemoryProductRepository;
use Tests\Fakes\InMemoryWebhookSubscriptionRepository;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryApi.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 10 public API & integrations tests ===\n";

// ── API keys ───────────────────────────────────────────────────────
echo "\n-- API keys --\n";
$keyRepo = new InMemoryApiKeyRepository();
$keys = new ApiKeyService($keyRepo);

$created = $keys->generate(7, 'CI token', [ApiScope::ORDERS_READ, 'bogus.scope', ApiScope::PRODUCTS_READ]);
$check('generate returns a token', is_string($created['token']) && $created['token'] !== '');
$check('token uses the gx_<prefix>_<secret> format',
    (bool) preg_match('/^gx_[0-9a-f]{12}_[0-9a-f]{40}$/', $created['token']), $created['token']);
$check('invalid scopes are filtered out',
    $created['scopes'] === [ApiScope::ORDERS_READ, ApiScope::PRODUCTS_READ]);
$check('plaintext token is not persisted',
    ($keyRepo->rows[$created['id']]['token_hash'] ?? '') !== $created['token']
    && strlen((string) $keyRepo->rows[$created['id']]['token_hash']) === 64);

$auth = $keys->authenticate($created['token']);
$check('valid token authenticates', $auth !== null);
$check('authenticated key exposes decoded scopes',
    $auth !== null && ($auth['scope_list'] ?? []) === [ApiScope::ORDERS_READ, ApiScope::PRODUCTS_READ]);
$check('authenticate refreshes last_used_at', $keyRepo->rows[$created['id']]['last_used_at'] !== null);
$check('hasScope true for granted scope', $auth !== null && $keys->hasScope($auth, ApiScope::ORDERS_READ));
$check('hasScope false for ungranted scope', $auth !== null && !$keys->hasScope($auth, ApiScope::WEBHOOKS_MANAGE));

$check('garbage token is rejected', $keys->authenticate('not-a-token') === null);
$check('well-formed but unknown token is rejected',
    $keys->authenticate('gx_' . str_repeat('a', 12) . '_' . str_repeat('b', 40)) === null);
$check('tampered secret is rejected', $keys->authenticate($created['token'] . 'x') === null);

$keys->revoke($created['id'], 7);
$check('revoked token no longer authenticates', $keys->authenticate($created['token']) === null);

$expired = $keys->generate(7, 'expired', [ApiScope::PRODUCTS_READ], 120, date('Y-m-d H:i:s', time() - 3600));
$check('expired token is rejected', $keys->authenticate($expired['token']) === null);

// ── Webhook subscriptions + fan-out ────────────────────────────────
echo "\n-- Webhooks --\n";
$subRepo = new InMemoryWebhookSubscriptionRepository();
$driver = new ArrayQueueDriver();
$webhooks = new WebhookService($subRepo, new Dispatcher($driver));

$sub = $webhooks->subscribe(7, 'https://partner.example.com/hooks', [WebhookEvent::ORDER_PAID]);
$check('subscribe returns a 64-char signing secret', strlen($sub['secret']) === 64);
$check('subscribe stores sanitized events', $sub['events'] === [WebhookEvent::ORDER_PAID]);

$threw = false;
try {
    $webhooks->subscribe(7, 'not-a-url', []);
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check('subscribe rejects an invalid URL', $threw);

// Emit a matching event.
$dispatched = $webhooks->emit(WebhookEvent::ORDER_PAID, ['order_number' => 'ORD-9']);
$check('emit dispatches to the matching subscription', $dispatched === 1);
$check('a webhook.dispatch job is queued', $driver->size('webhooks') === 1);
$msg = $driver->pop('webhooks');
$check('queued job is webhook.dispatch', $msg !== null && $msg->name === 'webhook.dispatch');
$check('job payload carries url/event/secret/data',
    $msg !== null
    && ($msg->payload['url'] ?? '') === 'https://partner.example.com/hooks'
    && ($msg->payload['event'] ?? '') === WebhookEvent::ORDER_PAID
    && ($msg->payload['secret'] ?? '') === $sub['secret']
    && (($msg->payload['data']['order_number'] ?? '') === 'ORD-9'));
$check('emit marks the subscription delivered', $subRepo->rows[$sub['id']]['last_delivered_at'] !== null);

// Non-matching event => no delivery.
$check('emit to an unsubscribed event delivers nothing',
    $webhooks->emit(WebhookEvent::PAYOUT_PROCESSED, []) === 0);

// Wildcard subscription receives any known event.
$driver2 = new ArrayQueueDriver();
$webhooks2 = new WebhookService($subRepo, new Dispatcher($driver2));
$webhooks2->subscribe(7, 'https://partner.example.com/all', []); // defaults to '*'
$check('wildcard subscription receives a specific event',
    $webhooks2->emit(WebhookEvent::PRODUCT_APPROVED, ['product_id' => 3]) === 1);
$check('invalid/wildcard event name is not emitted', $webhooks2->emit('*', []) === 0);
$check('unknown event name is not emitted', $webhooks2->emit('nonsense.event', []) === 0);

// ── JSON envelope ──────────────────────────────────────────────────
echo "\n-- JSON envelope --\n";
$products = new InMemoryProductRepository();
$products->create(['seller_id' => 1, 'title' => 'Alpha Theme', 'slug' => 'alpha-theme',
    'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 499.00, 'currency' => 'INR']);
$products->create(['seller_id' => 1, 'title' => 'Beta Plugin', 'slug' => 'beta-plugin',
    'status' => 'approved', 'scan_status' => 'clean', 'base_price' => 999.00, 'currency' => 'INR']);

$controller = new ProductController($products);
$req = new Request('GET', '/api/v1/products', [], [], [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/products', 'REMOTE_ADDR' => '127.0.0.1',
]);
$listRes = $controller->index($req);
$listBody = json_decode($listRes->body(), true);
$check('list endpoint returns 200', $listRes->status() === 200);
$check('success envelope has data + meta',
    is_array($listBody) && isset($listBody['data']) && isset($listBody['meta']));
$check('meta carries pagination', ($listBody['meta']['per_page'] ?? null) === 20 && ($listBody['meta']['page'] ?? null) === 1);
$check('products are presented as curated fields',
    isset($listBody['data'][0]['slug'], $listBody['data'][0]['price'])
    && !array_key_exists('seller_id', $listBody['data'][0]));

$showReq = new Request('GET', '/api/v1/products/nope', [], [], [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/products/nope', 'REMOTE_ADDR' => '127.0.0.1',
]);
$showRes = $controller->show($showReq, 'nope');
$showBody = json_decode($showRes->body(), true);
$check('missing product returns 404', $showRes->status() === 404);
$check('error envelope has error.code + error.message',
    isset($showBody['error']['code'], $showBody['error']['message']) && $showBody['error']['code'] === 'not_found');

// ── OpenAPI 3 contract test ────────────────────────────────────────
echo "\n-- OpenAPI contract --\n";
$specRaw = (string) file_get_contents($basePath . '/resources/openapi.json');
$spec = json_decode($specRaw, true);
$check('openapi.json is valid JSON', is_array($spec));
$check('declares OpenAPI 3', is_array($spec) && str_starts_with((string) ($spec['openapi'] ?? ''), '3.'));
$check('documents paths', is_array($spec['paths'] ?? null) && $spec['paths'] !== []);

// Every documented path+method must resolve to a registered route.
$app = (new App($basePath))->boot();
$router = $app->container()->get(\App\Http\Router::class);

$unmatched = [];
foreach (($spec['paths'] ?? []) as $path => $methods) {
    $concrete = preg_replace('/\{[^}]+\}/', 'sample', (string) $path);
    foreach (array_keys((array) $methods) as $method) {
        $r = new Request(strtoupper((string) $method), $concrete, [], [], [
            'REQUEST_METHOD' => strtoupper((string) $method),
            'REQUEST_URI'    => $concrete,
            'REMOTE_ADDR'    => '127.0.0.1',
        ]);
        if ($router->match($r) === null) {
            $unmatched[] = strtoupper((string) $method) . ' ' . $path;
        }
    }
}
$check('every documented path is a registered route', $unmatched === [], implode('; ', $unmatched));

// ── Enum helpers ───────────────────────────────────────────────────
echo "\n-- Catalogues --\n";
$check('ApiScope::sanitize dedupes + validates',
    ApiScope::sanitize(['orders.read', 'orders.read', 'x']) === ['orders.read']);
$check('WebhookEvent::sanitize defaults to wildcard when empty',
    WebhookEvent::sanitize([]) === ['*']);
$check('WebhookEvent::isValid accepts known + wildcard',
    WebhookEvent::isValid('order.paid') && WebhookEvent::isValid('*') && !WebhookEvent::isValid('nope'));

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — all Phase 10 assertions passed.\n";
    exit(0);
}
echo "FAILED — {$failures} assertion(s) failed.\n";
exit(1);
