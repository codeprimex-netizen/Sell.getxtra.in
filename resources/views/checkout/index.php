<?php
/**
 * @var array<int,array<string,mixed>> $items
 * @var array<string,\App\Domain\Commerce\Money> $totals
 * @var string $key @var string $gateway @var string $csrf_token
 */
$items = $items ?? [];
?>
<div class="card wide">
  <h1>Checkout</h1>

  <?php if ($items === []): ?>
    <p style="color:#94a3b8">Your cart is empty. <a href="/products">Browse products</a>.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Product</th><th style="text-align:right">Price</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr><td><?= e((string) $it['title']) ?></td><td style="text-align:right"><?= e(number_format((float) $it['unit_price'], 2)) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <form action="/checkout" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <input type="hidden" name="idempotency_key" value="<?= e($key) ?>">
      <input type="hidden" name="gateway" value="<?= e($gateway) ?>">

      <label>Coupon code (optional)</label>
      <input type="text" name="coupon" placeholder="WELCOME10">

      <table style="max-width:320px;margin:1rem 0">
        <tr><th>Subtotal</th><td style="text-align:right"><?= e($totals['subtotal']->format()) ?></td></tr>
        <tr><th>Tax (GST)</th><td style="text-align:right"><?= e($totals['tax']->format()) ?></td></tr>
        <tr><th>Total</th><td style="text-align:right"><strong><?= e($totals['total']->format()) ?></strong></td></tr>
      </table>

      <button type="submit">Pay <?= e($totals['total']->format()) ?></button>
      <p class="meta">Secure checkout via <?= e(ucfirst($gateway)) ?>. Coupons and tax are recalculated server-side.</p>
    </form>
  <?php endif; ?>
</div>
