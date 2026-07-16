<?php
/** @var array<int,array<string,mixed>> $orders */
$orders = $orders ?? [];
$badge = static fn (string $s): string => [
    'pending' => '#d97706', 'paid' => '#059669', 'failed' => '#dc2626',
    'refunded' => '#475569', 'partially_refunded' => '#0891b2',
][$s] ?? '#475569';
?>
<div class="card wide">
  <h1>My orders</h1>
  <table>
    <thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e((string) $o['order_number']) ?></td>
          <td><?= e((string) $o['created_at']) ?></td>
          <td><?= e(number_format((float) $o['total'], 2)) ?> <?= e((string) $o['currency']) ?></td>
          <td><span class="pill" style="background:<?= e($badge((string) $o['status'])) ?>;color:#fff"><?= e(ucwords(str_replace('_', ' ', (string) $o['status']))) ?></span></td>
          <td><a href="/orders/<?= (int) $o['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($orders === []): ?><tr><td colspan="5" style="color:#94a3b8">No orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="meta"><a href="/account/library">View my downloads &rarr;</a></p>
</div>
