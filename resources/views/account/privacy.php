<?php
/** @var array<int,string> $consent_types @var array<string,bool> $granted @var array<int,array<string,mixed>> $requests @var string $csrf_token @var string|null $download_error */
$consent_types = $consent_types ?? [];
$granted = $granted ?? [];
$requests = $requests ?? [];
$label = static fn (string $t): string => ucwords(str_replace('_', ' ', $t));
?>
<div class="card">
  <h1>Privacy &amp; your data</h1>
  <p class="sub">Manage consent, download a copy of your data, or request erasure of your account (GDPR / DPDP).</p>

  <?php if (!empty($download_error)): ?>
    <p style="color:#f87171"><?= e((string) $download_error) ?></p>
  <?php endif; ?>

  <h2>Consent preferences</h2>
  <form action="/account/privacy/consent" method="post">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <?php foreach ($consent_types as $type): ?>
      <label style="display:block;font-weight:400;margin:.35rem 0">
        <input type="checkbox" name="consents[]" value="<?= e($type) ?>" <?= !empty($granted[$type]) ? 'checked' : '' ?>>
        <?= e($label($type)) ?>
      </label>
    <?php endforeach; ?>
    <button type="submit" style="width:auto;padding:.4rem 1rem">Save preferences</button>
  </form>

  <h2 style="margin-top:1.5rem">Your data</h2>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <form action="/account/privacy/export" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button type="submit" style="width:auto;padding:.4rem 1rem">Request data export</button>
    </form>
    <form action="/account/privacy/erasure" method="post"
          onsubmit="return confirm('This will permanently anonymize your account. Continue?')">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button type="submit" style="width:auto;padding:.4rem 1rem;background:#7f1d1d">Request account erasure</button>
    </form>
  </div>

  <h2 style="margin-top:1.5rem">Request history</h2>
  <table>
    <thead><tr><th>Type</th><th>Status</th><th>Requested</th><th>Completed</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= e(ucfirst((string) ($r['type'] ?? ''))) ?></td>
          <td><?= e((string) ($r['status'] ?? '')) ?></td>
          <td><?= e((string) ($r['requested_at'] ?? '')) ?></td>
          <td><?= e((string) ($r['completed_at'] ?? '')) ?></td>
          <td>
            <?php if (($r['type'] ?? '') === 'export' && ($r['status'] ?? '') === 'completed' && !empty($r['download_key'])): ?>
              <a href="/account/privacy/export/<?= e((string) $r['token']) ?>">Download</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($requests === []): ?>
        <tr><td colspan="5" style="color:#94a3b8">No requests yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
