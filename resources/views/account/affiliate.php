<?php
/** @var bool $enabled @var array<string,mixed> $stats @var string $link @var string $csrf_token */
$enabled = $enabled ?? false;
$stats = $stats ?? ['enrolled' => false];
$sym = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$stats['currency'] ?? 'INR'] ?? '';
?>
<div class="card">
  <h1>Affiliate program</h1>
  <p class="sub">Earn commission when people you refer make their first purchase.</p>

  <?php if (!$enabled): ?>
    <p style="color:#94a3b8">The affiliate program is not currently available.</p>
  <?php elseif (empty($stats['enrolled'])): ?>
    <p>Join the program to get your unique referral link.</p>
    <form action="/account/affiliate/enroll" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button type="submit" style="width:auto;padding:.4rem 1rem">Join now</button>
    </form>
  <?php else: ?>
    <h2>Your referral link</h2>
    <p><code style="word-break:break-all"><?= e($link) ?></code></p>
    <p class="sub">Commission rate: <?= e((string) $stats['rate']) ?>%</p>

    <h2 style="margin-top:1.25rem">Funnel</h2>
    <table>
      <thead><tr><th>Clicks</th><th>Signups</th><th>Conversions</th></tr></thead>
      <tbody>
        <tr>
          <td><?= (int) $stats['clicks'] ?></td>
          <td><?= (int) $stats['signups'] ?></td>
          <td><?= (int) $stats['conversions'] ?></td>
        </tr>
      </tbody>
    </table>

    <?php $wallet = $wallet ?? null; $payouts = $payouts ?? []; ?>
    <h2 style="margin-top:1.25rem">Earnings</h2>
    <table>
      <thead><tr><th>Pending</th><th>Cleared</th><th>Reserved</th><th>Available</th></tr></thead>
      <tbody>
        <tr>
          <td><?= e($sym) ?><?= number_format((float) $stats['pending'], 2) ?></td>
          <td><?= e($sym) ?><?= number_format((float) $stats['cleared'], 2) ?></td>
          <td><?= e($sym) ?><?= number_format((float) ($wallet['reserved'] ?? 0), 2) ?></td>
          <td><strong><?= e($sym) ?><?= number_format((float) ($wallet['available'] ?? 0), 2) ?></strong></td>
        </tr>
      </tbody>
    </table>
    <p class="sub">Pending commission clears after the buyer's refund window closes; cleared, unreserved funds are withdrawable.</p>

    <?php if ($wallet !== null): ?>
      <h2 style="margin-top:1.25rem">Request a payout</h2>
      <?php if ((float) $wallet['available'] >= (float) $wallet['min']): ?>
        <form action="/account/affiliate/payout" method="post" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
          <label style="margin:0">Amount (<?= e($sym) ?>)
            <input type="text" name="amount" value="<?= number_format((float) $wallet['available'], 2, '.', '') ?>" style="width:160px">
          </label>
          <button type="submit" style="width:auto;padding:.5rem 1.1rem">Withdraw</button>
        </form>
        <p class="sub">Minimum payout: <?= e($sym) ?><?= number_format((float) $wallet['min'], 2) ?></p>
      <?php else: ?>
        <p class="sub">You need at least <?= e($sym) ?><?= number_format((float) $wallet['min'], 2) ?> available to request a payout.</p>
      <?php endif; ?>

      <h2 style="margin-top:1.25rem">Payout history</h2>
      <table>
        <thead><tr><th>Amount</th><th>Status</th><th>Requested</th><th>Ref</th></tr></thead>
        <tbody>
          <?php foreach ($payouts as $p): ?>
            <tr>
              <td><?= e($sym) ?><?= number_format((float) $p['amount'], 2) ?></td>
              <td><?= e((string) $p['status']) ?></td>
              <td><?= e((string) ($p['requested_at'] ?? '')) ?></td>
              <td><?= e((string) ($p['gateway_ref'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($payouts === []): ?><tr><td colspan="4" style="color:#94a3b8">No payouts yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
