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

    <h2 style="margin-top:1.25rem">Earnings</h2>
    <table>
      <thead><tr><th>Pending</th><th>Cleared</th></tr></thead>
      <tbody>
        <tr>
          <td><?= e($sym) ?><?= number_format((float) $stats['pending'], 2) ?></td>
          <td><?= e($sym) ?><?= number_format((float) $stats['cleared'], 2) ?></td>
        </tr>
      </tbody>
    </table>
    <p class="sub">Pending commission clears after the buyer's refund window closes.</p>
  <?php endif; ?>
</div>
