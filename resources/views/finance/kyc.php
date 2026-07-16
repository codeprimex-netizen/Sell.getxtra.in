<?php
/** @var array<int,array<string,mixed>> $pending @var string $csrf_token */
$pending = $pending ?? [];
?>
<div class="card wide">
  <h1>KYC review queue</h1>
  <table>
    <thead><tr><th>Seller</th><th>Email</th><th>Reference</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($pending as $s): ?>
        <tr>
          <td><?= e((string) $s['display_name']) ?></td>
          <td><?= e((string) ($s['email'] ?? '')) ?></td>
          <td><?= e((string) ($s['kyc_ref'] ?? '—')) ?></td>
          <td>
            <div style="display:flex;gap:.3rem">
              <form action="/finance/kyc/<?= (int) $s['user_id'] ?>/verify" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#059669;color:#fff">Verify</button></form>
              <form action="/finance/kyc/<?= (int) $s['user_id'] ?>/reject" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#3b0d0d;color:#fca5a5">Reject</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($pending === []): ?><tr><td colspan="4" style="color:#94a3b8">No pending KYC submissions.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
