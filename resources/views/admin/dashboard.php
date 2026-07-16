<?php
/**
 * @var array<string,int|float> $overview
 * @var array<int,array<string,mixed>> $top_sellers
 * @var int $open_disputes
 */
$overview = $overview ?? [];
$top_sellers = $top_sellers ?? [];
$card = static fn (string $label, string $value): string =>
    '<div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;padding:1rem">'
    . '<div style="color:#94a3b8;font-size:.8rem">' . e($label) . '</div>'
    . '<div style="font-size:1.5rem;font-weight:700;color:#38bdf8">' . e($value) . '</div></div>';
?>
<div class="card wide">
  <h1>Admin dashboard</h1>
  <p class="sub">Marketplace operations at a glance.</p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem">
    <?= $card('GMV', '₹' . number_format((float) ($overview['gmv'] ?? 0), 2)) ?>
    <?= $card('Paid orders', (string) (int) ($overview['paid_orders'] ?? 0)) ?>
    <?= $card('Users', (string) (int) ($overview['users'] ?? 0)) ?>
    <?= $card('Products', (string) (int) ($overview['products'] ?? 0)) ?>
    <?= $card('Pending moderation', (string) (int) ($overview['pending_products'] ?? 0)) ?>
    <?= $card('Open disputes', (string) (int) $open_disputes) ?>
  </div>

  <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="/admin/moderation">Moderation queue</a> ·
    <a href="/admin/users">Users</a> ·
    <a href="/admin/categories">Categories</a> ·
    <a href="/admin/coupons">Coupons</a> ·
    <a href="/admin/disputes">Disputes</a> ·
    <a href="/admin/settings">Settings</a>
  </div>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Top sellers</h1>
  <table>
    <thead><tr><th>Seller</th><th>Earnings</th><th>Items sold</th></tr></thead>
    <tbody>
      <?php foreach ($top_sellers as $s): ?>
        <tr><td><?= e((string) ($s['seller_name'] ?? ('#' . $s['seller_id']))) ?></td>
            <td>₹<?= e(number_format((float) $s['earnings'], 2)) ?></td>
            <td><?= (int) $s['items_sold'] ?></td></tr>
      <?php endforeach; ?>
      <?php if ($top_sellers === []): ?><tr><td colspan="3" style="color:#94a3b8">No sales yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
