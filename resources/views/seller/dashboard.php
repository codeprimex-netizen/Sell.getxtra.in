<?php
/**
 * @var array{units:int,revenue:float,earnings:float,views:int,products:int} $summary
 * @var float $conversion
 * @var array{cleared:float,pending:float,reserved:float,available:float} $wallet
 * @var array<int,array<string,mixed>> $top_products
 * @var array<string,mixed>|null $profile
 */
$summary = $summary ?? [];
$wallet = $wallet ?? [];
$top = $top_products ?? [];
$kyc = (string) ($profile['kyc_status'] ?? 'none');
$card = static fn (string $l, string $v): string =>
    '<div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;padding:1rem">'
    . '<div style="color:#94a3b8;font-size:.8rem">' . e($l) . '</div>'
    . '<div style="font-size:1.4rem;font-weight:700;color:#38bdf8">' . e($v) . '</div></div>';
?>
<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h1>Seller dashboard</h1>
    <div><a href="/seller/products">Products</a> · <a href="/seller/payouts">Payouts</a> · <a href="/seller/profile">Profile</a></div>
  </div>

  <?php if ($kyc !== 'verified'): ?>
    <div class="alert err">KYC status: <strong><?= e(ucfirst($kyc)) ?></strong>. Complete <a href="/seller/profile">verification</a> to sell and withdraw.</div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem">
    <?= $card('Units sold', (string) (int) ($summary['units'] ?? 0)) ?>
    <?= $card('Revenue', '₹' . number_format((float) ($summary['revenue'] ?? 0), 2)) ?>
    <?= $card('Earnings', '₹' . number_format((float) ($summary['earnings'] ?? 0), 2)) ?>
    <?= $card('Views', (string) (int) ($summary['views'] ?? 0)) ?>
    <?= $card('Conversion', ($conversion ?? 0) . '%') ?>
    <?= $card('Available', '₹' . number_format((float) ($wallet['available'] ?? 0), 2)) ?>
    <?= $card('Pending', '₹' . number_format((float) ($wallet['pending'] ?? 0), 2)) ?>
  </div>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Top products</h1>
  <table>
    <thead><tr><th>Product</th><th>Sales</th><th>Views</th><th>Rating</th></tr></thead>
    <tbody>
      <?php foreach ($top as $p): ?>
        <tr><td><a href="/product/<?= e((string) $p['slug']) ?>"><?= e((string) $p['title']) ?></a></td>
            <td><?= (int) $p['sales_count'] ?></td><td><?= (int) $p['views'] ?></td>
            <td><?= e(number_format((float) $p['avg_rating'], 1)) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($top === []): ?><tr><td colspan="4" style="color:#94a3b8">No products yet. <a href="/seller/products/create">Create one</a>.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
