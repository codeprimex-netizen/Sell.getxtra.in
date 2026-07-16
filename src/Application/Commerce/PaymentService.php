<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\CouponRepositoryInterface;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Domain\Commerce\OrderStatus;
use App\Domain\Commerce\PaymentEvent;
use App\Domain\Commerce\PaymentRepositoryInterface;
use App\Domain\Commerce\PaymentStatus;
use App\Domain\Commerce\WebhookEventRepositoryInterface;
use App\Application\Api\WebhookService;
use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Observability\Logger;
use App\Infrastructure\Payment\PaymentGatewayRegistry;
use App\Infrastructure\Queue\Dispatcher;

/**
 * Processes gateway webhooks (Req 9.3 / 9.6). Verifies the signature, then
 * de-duplicates via webhook_events, then applies the payment outcome. Every
 * step is idempotent: an order only transitions out of "pending" once, so
 * entitlements and ledger entries are never created twice.
 */
final class PaymentService
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private WebhookEventRepositoryInterface $webhookEvents,
        private OrderRepositoryInterface $orders,
        private PaymentRepositoryInterface $payments,
        private CouponRepositoryInterface $coupons,
        private EntitlementService $entitlements,
        private LedgerService $ledger,
        private Logger $logger,
        private ?Dispatcher $dispatcher = null,
        private ?WebhookService $webhooks = null,
    ) {
    }

    /**
     * Handle a raw webhook. Returns true when accepted (including duplicates
     * and irrelevant events); false only when the signature is invalid.
     */
    public function handleWebhook(string $gatewayName, string $rawBody, string $signature): bool
    {
        if (!$this->gateways->has($gatewayName)) {
            return false;
        }
        $gateway = $this->gateways->get($gatewayName);

        if (!$gateway->verifyWebhookSignature($rawBody, $signature)) {
            $this->logger->warning('Rejected webhook: bad signature', ['gateway' => $gatewayName]);
            return false;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return true; // malformed but authentic-enough; nothing to do
        }

        $event = $gateway->parseEvent($payload);
        if ($event === null) {
            return true; // event type we don't act on
        }

        // Idempotent intake: only the first sighting is processed.
        if (!$this->webhookEvents->recordIfNew($gatewayName, $event->eventId, $payload)) {
            return true;
        }

        $this->applyEvent($event);
        $this->webhookEvents->markProcessed($gatewayName, $event->eventId);

        return true;
    }

    private function applyEvent(PaymentEvent $event): void
    {
        $order = $this->orders->findByNumber($event->orderNumber);
        if ($order === null) {
            $this->logger->warning('Webhook for unknown order', ['order' => $event->orderNumber]);
            return;
        }

        // Only act while the order is still pending (idempotency guard).
        if (($order['status'] ?? '') !== OrderStatus::Pending->value) {
            return;
        }

        $payment = $this->payments->findByOrder((int) $order['id']);

        if ($event->status === PaymentStatus::Captured) {
            $this->markPaid($order, $payment, $event);
        } elseif ($event->status === PaymentStatus::Failed) {
            if ($payment !== null) {
                $this->payments->updateStatus((int) $payment['id'], 'failed', $event->gatewayRef);
            }
            $this->orders->updateStatus((int) $order['id'], OrderStatus::Failed->value);
        }
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed>|null $payment
     */
    private function markPaid(array $order, ?array $payment, PaymentEvent $event): void
    {
        $orderId = (int) $order['id'];

        if ($payment !== null) {
            $this->payments->updateStatus((int) $payment['id'], 'captured', $event->gatewayRef);
        }
        $this->orders->updateStatus($orderId, OrderStatus::Paid->value);

        $items = $this->orders->items($orderId);
        $this->entitlements->grantForOrder((int) $order['buyer_id'], $items);
        $this->ledger->recordSale($orderId, $items, (string) $order['currency']);

        if (!empty($order['coupon_id'])) {
            $this->coupons->incrementUsage((int) $order['coupon_id']);
        }

        // Fan out async work (Phase 9): generate the invoice off the request
        // path and notify the buyer. Under the sync driver these run inline.
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch('invoice.generate', ['order_id' => $orderId]);
            $this->dispatcher->dispatch('notification.push', [
                'user_id' => (int) $order['buyer_id'],
                'type'    => 'order_paid',
                'data'    => [
                    'order_number' => (string) $order['order_number'],
                    'total'        => (string) ($order['total'] ?? ''),
                    'currency'     => (string) ($order['currency'] ?? ''),
                ],
            ]);
        }

        // Notify subscribed integrations (Req 19.4) — signed + retried async.
        $this->webhooks?->emit(WebhookEvent::ORDER_PAID, [
            'order_number' => (string) $order['order_number'],
            'buyer_id'     => (int) $order['buyer_id'],
            'currency'     => (string) ($order['currency'] ?? ''),
            'total'        => (float) ($order['total'] ?? 0),
            'status'       => OrderStatus::Paid->value,
        ]);

        $this->logger->info('Order paid', ['order_id' => $orderId, 'order' => $order['order_number']]);
    }
}
