<?php
/** @var array<int,array<string,mixed>> $products @var string $csrf_token */
$products = $products ?? [];
$badge = static function (string $status): string {
    $colors = [
        'draft' => '#64748b', 'pending' => '#d97706', 'in_review' => '#0891b2',
        'approved' => '#059669', 'rejected' => '#dc2626', 'suspended' => '#b91c1c', 'archived' => '#475569',
    ];
    return $colors[$status] ?? '#475569';
};
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h1>My products</h1>
    <a href="/seller/products/create"><button type="button" style="width:auto;margin:0;padding:.5rem 1rem">New product</button></a>
  </div>

  <table>
    <thead><tr><th>Title</th><th>Status</th><th>Scan</th><th>Price</th><th>Views</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= e((string) $p['title']) ?></td>
          <td><span class="pill" style="background:<?= e($badge((string) $p['status'])) ?>;color:#fff"><?= e(ucwords(str_replace('_', ' ', (string) $p['status']))) ?></span></td>
          <td><?= e((string) $p['scan_status']) ?></td>
          <td><?= e(number_format((float) $p['base_price'], 2)) ?> <?= e((string) $p['currency']) ?></td>
          <td><?= (int) $p['views'] ?></td>
          <td><a href="/seller/products/<?= (int) $p['id'] ?>/edit">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($products === []): ?>
        <tr><td colspan="6" style="color:#94a3b8">No products yet. Create your first listing.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
