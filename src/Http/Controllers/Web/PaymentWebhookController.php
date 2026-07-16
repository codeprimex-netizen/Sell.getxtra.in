<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Commerce\PaymentService;
use App\Config\Config;
use App\Domain\Commerce\OrderRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Payment\OfflineGateway;

/**
 * Receives gateway webhooks (Req 9.3). This path is CSRF-exempt (see
 * VerifyCsrf) because it is authenticated by the gateway's HMAC signature,
 * not a session. Also hosts a dev-only "pay" page for the offline gateway.
 */
final class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private OrderRepositoryInterface $orders,
    ) {
    }

    /** POST /payments/{gateway}/webhook */
    public function handle(Request $request, string $gateway): Response
    {
        // Real gateways POST a raw JSON body; the dev offline page posts a
        // "payload" form field (browsers can't send raw bodies from forms).
        $raw = $request->input('payload') !== null
            ? (string) $request->input('payload')
            : $request->rawBody();
        $signature = $this->signatureHeader($request, $gateway);

        $ok = $this->payments->handleWebhook($gateway, $raw, $signature);

        return $ok
            ? Response::json(['received' => true])
            : Response::apiError('invalid_signature', 'Webhook signature verification failed.', 400);
    }

    /**
     * Dev-only page (offline gateway) that simulates the buyer paying by
     * posting a correctly-signed webhook to complete the order. Never enabled
     * in production.
     */
    public function offlinePay(Request $request, string $orderNumber): Response
    {
        if ((string) Config::get('app.env') === 'production') {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        $order = $this->orders->findByNumber($orderNumber);
        if ($order === null) {
            return $this->view($request, 'errors.catalog-404', [], 404);
        }

        $gateway = OfflineGateway::fromConfig();
        $body = json_encode([
            'event_id'     => 'off_evt_' . $orderNumber,
            'order_number' => $orderNumber,
            'gateway_ref'  => 'off_' . substr(sha1($orderNumber), 0, 12),
            'status'       => 'paid',
        ]) ?: '{}';

        return $this->view($request, 'checkout.offline-pay', [
            'order'     => $order,
            'body'      => $body,
            'signature' => $gateway->signBody($body),
        ]);
    }

    private function signatureHeader(Request $request, string $gateway): string
    {
        return match ($gateway) {
            'stripe'   => (string) ($request->header('Stripe-Signature') ?? ''),
            'razorpay' => (string) ($request->header('X-Razorpay-Signature') ?? ''),
            default    => (string) ($request->header('X-Signature') ?? $request->input('signature', '')),
        };
    }
}
