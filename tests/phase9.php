<?php

declare(strict_types=1);

/**
 * Phase 9 tests: durable queue (push/pop/ack, delayed retry), worker retry +
 * exponential backoff to the dead-letter queue, synchronous dispatch, in-app
 * notifications with email fan-out + preferences/unsubscribe, off-request
 * invoice generation, and the cron-style scheduler. In-memory + no DB.
 * Run: php tests/phase9.php
 */

use App\Application\Invoice\InvoiceService;
use App\Application\Notification\NotificationPreferenceService;
use App\Application\Notification\NotificationService;
use App\Infrastructure\Queue\ArrayQueueDriver;
use App\Infrastructure\Queue\Dispatcher;
use App\Infrastructure\Queue\JobHandler;
use App\Infrastructure\Queue\JobRegistry;
use App\Infrastructure\Queue\SyncQueueDriver;
use App\Infrastructure\Queue\Worker;
use App\Infrastructure\Scheduler\Scheduler;
use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageManager;
use App\Jobs\Handlers\SendEmailHandler;
use App\Jobs\Handlers\SendNotificationHandler;
use Tests\Fakes\ArrayMailer;
use Tests\Fakes\InMemoryNotificationPreferenceRepository;
use Tests\Fakes\InMemoryNotificationRepository;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\InMemorySettingsRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Fakes/InMemoryRepositories.php';
require __DIR__ . '/Fakes/InMemoryCatalog.php';
require __DIR__ . '/Fakes/InMemoryCommerce.php';
require __DIR__ . '/Fakes/InMemoryAdmin.php';
require __DIR__ . '/Fakes/InMemoryNotification.php';

\App\Config\Config::boot();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        $failures++;
    }
};

echo "=== Phase 9 async jobs, notifications & scheduler tests ===\n";

/** A stateful handler that records every call; optionally always throws. */
final class RecordingHandler implements JobHandler
{
    /** @var array<int, array<string,mixed>> */
    public array $calls = [];

    public function __construct(private bool $throws = false)
    {
    }

    public function handle(array $payload): void
    {
        $this->calls[] = $payload;
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
    }
}

// ── Queue: push / pop / ack ────────────────────────────────────────
echo "\n-- Queue driver --\n";
$driver = new ArrayQueueDriver();
$driver->push('email.send', ['to' => 'a@b.co'], 'mail');
$check('push increases size', $driver->size('mail') === 1);
$check('pop from empty queue is null', $driver->pop('default') === null);

$msg = $driver->pop('mail');
$check('pop returns the message', $msg !== null && $msg->name === 'email.send');
$check('pop carries the payload', $msg !== null && ($msg->payload['to'] ?? '') === 'a@b.co');
$driver->ack($msg);
$check('ack removes the message', $driver->size('mail') === 0);

// ── Queue: delayed retry (release) ─────────────────────────────────
$driver->push('x.job', []);
$m = $driver->pop();
$driver->release($m, 60);
$check('released message is not immediately available', $driver->pop() === null);
$check('released message still counts toward size', $driver->size() === 1);

$driver->push('y.job', []);
$m2 = $driver->pop();
// find the y.job message id by popping the immediately-available one
$driver->release($m2, 0);
$reclaimed = $driver->pop();
$check('release with no delay is re-poppable', $reclaimed !== null);
$check('attempts increment on release', $reclaimed !== null && $reclaimed->attempts === 1);

// ── Worker: success path ───────────────────────────────────────────
echo "\n-- Worker --\n";
$okHandler = new RecordingHandler(false);
$registry = new JobRegistry();
$registry->register('ok.job', static fn () => $okHandler);
$d1 = new ArrayQueueDriver();
$worker = new Worker($d1, $registry, null, 3, 0);
$d1->push('ok.job', ['n' => 1]);
$check('runOnce processes a job', $worker->runOnce() === true);
$check('handler ran once', count($okHandler->calls) === 1);
$check('successful job is acked', $d1->size() === 0);
$check('runOnce on empty queue returns false', $worker->runOnce() === false);

// ── Worker: retry with backoff then dead-letter ────────────────────
$badHandler = new RecordingHandler(true);
$reg2 = new JobRegistry();
$reg2->register('bad.job', static fn () => $badHandler);
$d2 = new ArrayQueueDriver();
$worker2 = new Worker($d2, $reg2, null, 2, 0); // maxAttempts=2, no backoff delay
$d2->push('bad.job', []);
$runs = 0;
while ($worker2->runOnce()) {
    if (++$runs > 10) {
        break; // safety
    }
}
$check('failing job is retried then dead-lettered', count($d2->failed) === 1);
$check('dead-lettered job left the main queue', $d2->size() === 0);
$check('handler attempted multiple times before DLQ', count($badHandler->calls) >= 2, count($badHandler->calls) . ' attempts');

// ── Dispatcher: synchronous inline execution ───────────────────────
echo "\n-- Dispatcher (sync) --\n";
$inlineHandler = new RecordingHandler(false);
$reg3 = new JobRegistry();
$reg3->register('inline.job', static fn () => $inlineHandler);
$dispatcher = new Dispatcher(new SyncQueueDriver($reg3));
$dispatcher->dispatch('inline.job', ['hello' => 'world']);
$check('sync dispatch runs the handler inline', count($inlineHandler->calls) === 1);
$check('sync dispatch passes the payload', ($inlineHandler->calls[0]['hello'] ?? '') === 'world');

// ── Notifications + email fan-out ──────────────────────────────────
echo "\n-- Notifications --\n";
$notifs = new InMemoryNotificationRepository();
$prefs = new InMemoryNotificationPreferenceRepository();
$mailer = new ArrayMailer();

$mailRegistry = new JobRegistry();
$mailRegistry->register('email.send', static fn () => new SendEmailHandler($mailer));
$mailDispatcher = new Dispatcher(new SyncQueueDriver($mailRegistry));

$notificationService = new NotificationService($notifs, $prefs, $mailDispatcher);

$id = $notificationService->notify(42, 'order_paid', ['order_number' => 'ORD-1']);
$check('notify returns an id', $id > 0);
$check('notification is unread', $notificationService->unreadCount(42) === 1);
$check('notify with no email sends no mail', $mailer->sent === []);

$notificationService->notify(42, 'order_paid', ['order_number' => 'ORD-2'], [
    'email'   => 'buyer@example.com',
    'subject' => 'Your order is confirmed',
    'template' => 'order_paid',
]);
$check('email fan-out enqueues + sends mail', count($mailer->sent) === 1);
$check('email delivered to buyer', ($mailer->sent[0]['to'] ?? '') === 'buyer@example.com');
$check('unread count reflects both notifications', $notificationService->unreadCount(42) === 2);

$rows = $notificationService->forUser(42);
$check('forUser lists newest first', ($rows[0]['data']['order_number'] ?? '') === 'ORD-2');

$notificationService->markRead((int) $rows[0]['id'], 42);
$check('markRead lowers unread count', $notificationService->unreadCount(42) === 1);
$notificationService->markAllRead(42);
$check('markAllRead clears unread', $notificationService->unreadCount(42) === 0);

// ── Preferences + unsubscribe ──────────────────────────────────────
echo "\n-- Preferences --\n";
$prefService = new NotificationPreferenceService($prefs);
$check('email allowed by default', $notificationService->emailAllowed(99) === true);

$token = $prefService->get(99)['unsubscribe_token'];
$check('unsubscribe token issued', is_string($token) && strlen($token) === 40);
$check('valid token unsubscribes', $prefService->unsubscribe($token) === true);
$check('unsubscribe disables email', $notificationService->emailAllowed(99) === false);
$check('unknown token is rejected', $prefService->unsubscribe('nope') === false);

// suppressed email preference blocks fan-out
$mailer2Count = count($mailer->sent);
$notificationService->notify(99, 'promo', ['x' => 1], [
    'email'   => 'opted-out@example.com',
    'subject' => 'Deals',
]);
$check('opted-out user receives no email', count($mailer->sent) === $mailer2Count);
$check('opted-out user still gets in-app notification', $notificationService->unreadCount(99) === 1);

// ── Invoice generation ─────────────────────────────────────────────
echo "\n-- Invoice --\n";
$orders = new InMemoryOrderRepository();
$orderId = $orders->create(
    [
        'order_number' => 'ORD-INV-1', 'buyer_id' => 7, 'currency' => 'INR',
        'subtotal' => 999.00, 'discount' => 0.0, 'tax' => 179.82, 'total' => 1178.82,
        'status' => 'paid',
    ],
    [
        ['product_id' => 1, 'title_snapshot' => 'Pro Theme', 'unit_price' => 999.00,
         'commission' => 199.80, 'seller_earning' => 799.20, 'seller_id' => 3],
    ],
);

$tmp = sys_get_temp_dir() . '/getxtra_inv_' . uniqid();
$storage = new StorageManager();
$storage->register('private', new LocalStorage($tmp . '/private', '', false));

$invoices = new InvoiceService($orders, $storage);
$key = $invoices->generate($orderId);
$check('generate returns the stored key', $key === 'invoices/ORD-INV-1.html');
$check('invoice document is stored', $storage->private()->exists($key));
$check('invoice key is saved on the order', ($orders->findById($orderId)['invoice_key'] ?? '') === $key);
$html = (string) $storage->private()->get($key);
$check('invoice includes the total', str_contains($html, '1,178.82'));
$check('invoice names the developer', str_contains($html, 'ANSHU E-MITRA AND CSC CENTER'));
$check('generate on missing order returns empty', $invoices->generate(99999) === '');

// ── Scheduler ──────────────────────────────────────────────────────
echo "\n-- Scheduler --\n";
$settings = new InMemorySettingsRepository();
$scheduler = new Scheduler($settings);
$ran = 0;
$scheduler->register('nightly', 1440, static function () use (&$ran): void {
    $ran++;
});
$scheduler->register('hourly', 60, static function () use (&$ran): void {
    $ran++;
});

$now = 1_000_000;
$check('task is due when never run', $scheduler->isDue('nightly', 1440, $now) === true);
$fired = $scheduler->run(false, $now);
$check('run executes all due tasks', count($fired) === 2 && $ran === 2);
$check('tasks are not due again immediately', $scheduler->run(false, $now + 30) === []);
$check('due again after the interval elapses', $scheduler->run(false, $now + 3600) === ['hourly']);
$check('force runs regardless of schedule', count($scheduler->run(true, $now + 3601)) === 2);
$check('task names are exposed', $scheduler->taskNames() === ['nightly', 'hourly']);

// ── Summary ────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "OK — all Phase 9 assertions passed.\n";
    exit(0);
}
echo "FAILED — {$failures} assertion(s) failed.\n";
exit(1);
