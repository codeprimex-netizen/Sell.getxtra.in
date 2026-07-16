<?php
/** @var array<string,mixed> $order @var array<int,array<string,mixed>> $items */
$order = $order ?? [];
$items = $items ?? [];
?>
<div class="card wide">
  <h1>Order <?= e((string) $order['order_number']) ?></h1>
  <p class="sub">Placed <?= e((string) $order['created_at']) ?> &mdash;
    status <strong><?= e(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></strong></p>

  <table>
    <thead><tr><th>Product</th><th style="text-align:right">Price</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e((string) $it['title_snapshot']) ?></td><td style="text-align:right"><?= e(number_format((float) $it['unit_price'], 2)) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table style="max-width:320px;margin-left:auto">
    <tr><th>Subtotal</th><td style="text-align:right"><?= e(number_format((float) $order['subtotal'], 2)) ?></td></tr>
    <tr><th>Discount</th><td style="text-align:right">-<?= e(number_format((float) $order['discount'], 2)) ?></td></tr>
    <tr><th>Tax</th><td style="text-align:right"><?= e(number_format((float) $order['tax'], 2)) ?></td></tr>
    <tr><th>Total</th><td style="text-align:right"><strong><?= e(number_format((float) $order['total'], 2)) ?> <?= e((string) $order['currency']) ?></strong></td></tr>
  </table>

  <?php if (($order['status'] ?? '') === 'paid' || ($order['status'] ?? '') === 'partially_refunded'): ?>
    <a href="/account/library"><button type="button">Go to downloads</button></a>
    <?php if (!empty($order['invoice_key'])): ?>
      <a href="/orders/<?= (int) $order['id'] ?>/invoice" target="_blank" rel="noopener">
        <button type="button" class="ghost">Download invoice</button>
      </a>
    <?php endif; ?>
  <?php endif; ?>
  <p class="meta"><a href="/orders">&larr; All orders</a></p>
</div>
