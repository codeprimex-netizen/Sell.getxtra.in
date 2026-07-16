<?php
/**
 * @var array<int,array<string,mixed>> $items
 * @var array{subtotal:\App\Domain\Commerce\Money,discount:\App\Domain\Commerce\Money,tax:\App\Domain\Commerce\Money,total:\App\Domain\Commerce\Money} $totals
 * @var string $csrf_token
 */
$items = $items ?? [];
?>
<div class="card wide">
  <h1>Your cart</h1>

  <table>
    <thead><tr><th>Product</th><th>Price</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><a href="/product/<?= e((string) $it['slug']) ?>"><?= e((string) $it['title']) ?></a></td>
          <td><?= e(number_format((float) $it['unit_price'], 2)) ?> <?= e((string) ($it['currency'] ?? 'INR')) ?></td>
          <td>
            <form action="/cart/remove" method="post" style="margin:0">
              <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
              <input type="hidden" name="product_id" value="<?= (int) $it['product_id'] ?>">
              <button type="submit" style="width:auto;margin:0;padding:.25rem .7rem;background:#3b0d0d;color:#fca5a5">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($items === []): ?>
        <tr><td colspan="3" style="color:#94a3b8">Your cart is empty. <a href="/products">Browse products</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($items !== []): ?>
    <table style="max-width:320px;margin-left:auto">
      <tr><th>Subtotal</th><td style="text-align:right"><?= e($totals['subtotal']->format()) ?></td></tr>
      <tr><th>Tax (GST)</th><td style="text-align:right"><?= e($totals['tax']->format()) ?></td></tr>
      <tr><th>Total</th><td style="text-align:right"><strong><?= e($totals['total']->format()) ?></strong></td></tr>
    </table>
    <a href="/checkout"><button type="button">Proceed to checkout</button></a>
  <?php endif; ?>
</div>
