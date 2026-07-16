<?php
/** @var array<int,array<string,mixed>> $payouts @var string $csrf_token */
$payouts = $payouts ?? [];
?>
<div class="card wide">
  <h1>Payout queue</h1>
  <p class="sub">Requested payouts awaiting processing.</p>
  <table>
    <thead><tr><th>Seller</th><th>Amount</th><th>Method</th><th>Requested</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($payouts as $p): ?>
        <tr>
          <td><?= e((string) ($p['seller_name'] ?? ('#' . $p['seller_id']))) ?><br><span style="color:#64748b;font-size:.78rem"><?= e((string) ($p['seller_email'] ?? '')) ?></span></td>
          <td>₹<?= e(number_format((float) $p['amount'], 2)) ?></td>
          <td><?= e((string) ($p['method'] ?? '—')) ?></td>
          <td><?= e((string) $p['requested_at']) ?></td>
          <td>
            <div style="display:flex;gap:.3rem">
              <form action="/finance/payouts/<?= (int) $p['id'] ?>/pay" method="post" style="margin:0;display:flex;gap:.2rem">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="text" name="gateway_ref" placeholder="ref" style="width:90px;padding:.25rem">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#059669;color:#fff">Mark paid</button>
              </form>
              <form action="/finance/payouts/<?= (int) $p['id'] ?>/reject" method="post" style="margin:0">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#3b0d0d;color:#fca5a5">Reject</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($payouts === []): ?><tr><td colspan="5" style="color:#94a3b8">No pending payouts.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
