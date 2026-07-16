<?php
/**
 * @var array{cleared:float,pending:float,reserved:float,available:float} $wallet
 * @var array<int,array<string,mixed>> $payouts
 * @var string $currency @var string $csrf_token
 */
$wallet = $wallet ?? [];
$payouts = $payouts ?? [];
?>
<div class="card wide">
  <h1>Payouts</h1>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem">
    <?php foreach (['available' => 'Available', 'pending' => 'Pending', 'reserved' => 'Reserved', 'cleared' => 'Cleared'] as $k => $label): ?>
      <div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;padding:1rem">
        <div style="color:#94a3b8;font-size:.8rem"><?= e($label) ?></div>
        <div style="font-size:1.3rem;font-weight:700;color:#38bdf8">₹<?= e(number_format((float) ($wallet[$k] ?? 0), 2)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <h1 style="font-size:1.1rem;margin-top:1.25rem">Request a payout</h1>
  <form action="/seller/payouts" method="post" style="display:flex;gap:.5rem;align-items:end">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <div><label>Amount (<?= e($currency ?? 'INR') ?>)</label><input type="text" name="amount"></div>
    <div><label>Method</label>
      <select name="method" style="padding:.6rem;border-radius:9px;background:#0b1220;color:#e2e8f0;border:1px solid #334155">
        <option value="bank">Bank</option><option value="upi">UPI</option><option value="paypal">PayPal</option>
      </select>
    </div>
    <button type="submit" style="width:auto;margin:0">Request</button>
  </form>

  <table>
    <thead><tr><th>Requested</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($payouts as $p): ?>
        <tr><td><?= e((string) $p['requested_at']) ?></td>
            <td>₹<?= e(number_format((float) $p['amount'], 2)) ?></td>
            <td><?= e((string) ($p['method'] ?? '—')) ?></td>
            <td><?= e(ucfirst((string) $p['status'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($payouts === []): ?><tr><td colspan="4" style="color:#94a3b8">No payouts yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
