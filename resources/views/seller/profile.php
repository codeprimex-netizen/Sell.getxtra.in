<?php
/** @var array<string,mixed>|null $profile @var string $csrf_token */
$profile = $profile ?? null;
$kyc = (string) ($profile['kyc_status'] ?? 'none');
$canSubmit = in_array($kyc, ['none', 'rejected'], true);
?>
<div class="card">
  <h1>Seller profile</h1>
  <?php if ($profile === null): ?>
    <p class="sub">You are not a seller yet. <a href="/seller/onboard">Become a seller</a>.</p>
  <?php else: ?>
    <p class="sub">Store: <strong><?= e((string) $profile['display_name']) ?></strong></p>
    <table>
      <tr><th>KYC status</th><td><?= e(ucfirst($kyc)) ?></td></tr>
      <tr><th>Payout method</th><td><?= e((string) ($profile['payout_method'] ?? 'not set')) ?></td></tr>
      <tr><th>Commission</th><td><?= e((string) ($profile['commission_rate'] ?? '20')) ?>%</td></tr>
    </table>

    <?php if ($canSubmit): ?>
      <h1 style="font-size:1.1rem;margin-top:1.25rem">Submit KYC</h1>
      <form action="/seller/kyc" method="post">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label>KYC reference / document id</label>
        <input type="text" name="kyc_ref" placeholder="e.g. PAN or GSTIN reference">
        <button type="submit">Submit for verification</button>
      </form>
    <?php elseif ($kyc === 'pending'): ?>
      <div class="alert ok" style="margin-top:1rem">Your KYC is under review.</div>
    <?php elseif ($kyc === 'verified'): ?>
      <div class="alert ok" style="margin-top:1rem">You are verified and can sell + withdraw. 🎉</div>
    <?php endif; ?>

    <h1 style="font-size:1.1rem;margin-top:1.25rem">Payout method</h1>
    <form action="/seller/payout-method" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <label>Method</label>
      <select name="method" style="width:100%;padding:.6rem;border-radius:9px;background:#0b1220;color:#e2e8f0;border:1px solid #334155">
        <option value="bank">Bank transfer</option><option value="upi">UPI</option><option value="paypal">PayPal</option>
      </select>
      <label>Details (account / UPI id)</label>
      <input type="text" name="details" placeholder="Stored encrypted">
      <button type="submit">Save payout method</button>
    </form>
  <?php endif; ?>
</div>
