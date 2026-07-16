<?php
/** @var array<string,mixed>|null $user @var array<int,string> $roles @var string $csrf_token */
$user = $user ?? [];
$roles = $roles ?? [];
$summary = $summary ?? [];
$orders = $summary['orders'] ?? ['recent' => [], 'total' => 0, 'spent' => 0.0, 'currency' => 'INR'];
$seller = $summary['seller'] ?? null;
$affiliate = $summary['affiliate'] ?? ['enrolled' => false];
$twoFactor = (int) ($user['two_factor_enabled'] ?? 0) === 1;
$verified = !empty($user['email_verified_at']);
$cur = (string) ($orders['currency'] ?? 'INR');
?>
<div class="card">
  <h1>Welcome, <?= e((string) ($user['name'] ?? 'there')) ?></h1>
  <p class="sub"><?= e((string) ($user['email'] ?? '')) ?></p>

  <div style="margin:.5rem 0 1rem">
    <?php foreach ($roles as $role): ?>
      <span class="pill"><?= e($role) ?></span>
    <?php endforeach; ?>
    <?php if ($roles === []): ?><span class="pill">buyer</span><?php endif; ?>
  </div>

  <table>
    <tr><th>Email verified</th><td><?= $verified ? 'Yes' : 'No' ?></td></tr>
    <tr><th>Two-factor auth</th><td><?= $twoFactor ? 'Enabled' : 'Disabled' ?></td></tr>
    <tr><th>Account status</th><td><?= e((string) ($user['status'] ?? 'active')) ?></td></tr>
  </table>

  <?php if ($twoFactor): ?>
    <form action="/2fa/disable" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button class="ghost" type="submit">Disable two-factor</button>
    </form>
  <?php else: ?>
    <a href="/2fa/setup"><button type="button">Enable two-factor authentication</button></a>
  <?php endif; ?>
</div>

<!-- KPI tiles ------------------------------------------------------ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin:1rem 0">
  <a href="/orders" style="text-decoration:none">
    <div class="card" style="margin:0;text-align:center">
      <div style="font-size:1.8rem;font-weight:700;color:#38bdf8"><?= (int) $orders['total'] ?></div>
      <div class="sub">Orders</div>
    </div>
  </a>
  <a href="/account/library" style="text-decoration:none">
    <div class="card" style="margin:0;text-align:center">
      <div style="font-size:1.8rem;font-weight:700;color:#38bdf8"><?= (int) ($summary['library']['count'] ?? 0) ?></div>
      <div class="sub">In your library</div>
    </div>
  </a>
  <a href="/account/wishlist" style="text-decoration:none">
    <div class="card" style="margin:0;text-align:center">
      <div style="font-size:1.8rem;font-weight:700;color:#38bdf8"><?= (int) ($summary['wishlist'] ?? 0) ?></div>
      <div class="sub">Wishlist</div>
    </div>
  </a>
  <div class="card" style="margin:0;text-align:center">
    <div style="font-size:1.4rem;font-weight:700;color:#e2e8f0"><?= e(money((float) $orders['spent'], $cur)) ?></div>
    <div class="sub">Total spent</div>
  </div>
</div>

<!-- Recent orders -------------------------------------------------- -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1 style="font-size:1.2rem">Recent orders</h1>
    <a href="/orders" style="font-size:.88rem">View all &rarr;</a>
  </div>
  <table>
    <thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach (($orders['recent'] ?? []) as $o): ?>
        <tr>
          <td><a href="/orders/<?= (int) $o['id'] ?>"><?= e((string) $o['order_number']) ?></a></td>
          <td><span class="pill"><?= e((string) $o['status']) ?></span></td>
          <td><?= e(money((float) $o['total'], (string) ($o['currency'] ?? $cur))) ?></td>
          <td><?= e((string) ($o['created_at'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (($orders['recent'] ?? []) === []): ?>
        <tr><td colspan="4" style="color:#94a3b8">No orders yet. <a href="/products">Browse products</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Seller earnings (sellers only) --------------------------------- -->
<?php if ($seller !== null): ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:baseline">
      <h1 style="font-size:1.2rem">Seller</h1>
      <a href="/seller/dashboard" style="font-size:.88rem">Seller console &rarr;</a>
    </div>
    <table>
      <tr><th>Products</th><td><?= (int) $seller['products'] ?></td></tr>
      <tr><th>Available to withdraw</th><td><strong><?= e(money((float) $seller['available'], $cur)) ?></strong></td></tr>
      <tr><th>Pending (clearing)</th><td><?= e(money((float) $seller['pending'], $cur)) ?></td></tr>
    </table>
  </div>
<?php endif; ?>

<!-- Affiliate ------------------------------------------------------ -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1 style="font-size:1.2rem">Affiliate</h1>
    <a href="/account/affiliate" style="font-size:.88rem">Affiliate dashboard &rarr;</a>
  </div>
  <?php if (!empty($affiliate['enrolled'])): ?>
    <table>
      <tr><th>Clicks / Signups / Conversions</th>
        <td><?= (int) ($affiliate['clicks'] ?? 0) ?> / <?= (int) ($affiliate['signups'] ?? 0) ?> / <?= (int) ($affiliate['conversions'] ?? 0) ?></td></tr>
      <tr><th>Commission (pending / cleared)</th>
        <td><?= e(money((float) ($affiliate['pending'] ?? 0), $cur)) ?> / <?= e(money((float) ($affiliate['cleared'] ?? 0), $cur)) ?></td></tr>
    </table>
  <?php else: ?>
    <p class="sub">Refer others and earn commission on their first purchase. <a href="/account/affiliate">Join the program &rarr;</a></p>
  <?php endif; ?>
</div>
