<?php
/**
 * Dev-only simulated gateway page (offline gateway). Posting this form sends
 * a correctly-signed webhook to complete the order — mimicking the buyer
 * paying on a hosted checkout page. Not available in production.
 *
 * @var array<string,mixed> $order @var string $body @var string $signature
 */
?>
<div class="card">
  <h1>Complete payment (dev)</h1>
  <p class="sub">Order <?= e((string) $order['order_number']) ?> —
     <strong><?= e(number_format((float) $order['total'], 2)) ?> <?= e((string) $order['currency']) ?></strong></p>
  <p style="color:#94a3b8;font-size:.85rem">This simulates the payment gateway. In production the buyer pays on the
     provider's hosted checkout and the provider posts the webhook.</p>

  <form action="/payments/offline/webhook" method="post">
    <input type="hidden" name="payload" value="<?= e($body) ?>">
    <input type="hidden" name="signature" value="<?= e($signature) ?>">
    <button type="submit">Pay now</button>
  </form>
  <p class="meta"><a href="/cart">Cancel</a></p>
</div>
