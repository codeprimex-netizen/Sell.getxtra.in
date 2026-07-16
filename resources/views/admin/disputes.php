<?php
/** @var array<int,array<string,mixed>> $disputes @var ?string $status @var string $csrf_token */
$disputes = $disputes ?? [];
?>
<div class="card wide">
  <h1>Disputes</h1>
  <p class="sub">
    <a href="/admin/disputes">All</a> ·
    <a href="/admin/disputes?status=open">Open</a> ·
    <a href="/admin/disputes?status=under_review">Under review</a>
  </p>

  <table>
    <thead><tr><th>Order</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($disputes as $d): ?>
        <tr>
          <td><?= e((string) ($d['order_number'] ?? ('#' . $d['order_id']))) ?><br>
            <span style="color:#64748b;font-size:.78rem">₹<?= e(number_format((float) ($d['total'] ?? 0), 2)) ?></span></td>
          <td style="max-width:220px"><?= e((string) $d['reason']) ?></td>
          <td><?= e(ucwords(str_replace('_', ' ', (string) $d['status']))) ?></td>
          <td>
            <?php if (in_array($d['status'], ['open', 'under_review'], true)): ?>
              <div style="display:flex;gap:.3rem;flex-wrap:wrap;align-items:center">
                <form action="/admin/disputes/<?= (int) $d['id'] ?>/resolve" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#059669;color:#fff">Resolve</button></form>
                <form action="/admin/disputes/<?= (int) $d['id'] ?>/reject" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#1e293b;color:#e2e8f0">Reject</button></form>
                <form action="/admin/disputes/<?= (int) $d['id'] ?>/refund" method="post" style="margin:0;display:flex;gap:.2rem">
                  <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                  <input type="text" name="amount" placeholder="amount" value="<?= e((string) ($d['total'] ?? '')) ?>" style="width:80px;padding:.25rem">
                  <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#3b0d0d;color:#fca5a5">Refund</button>
                </form>
              </div>
            <?php else: ?>
              <span style="color:#94a3b8"><?= e((string) ($d['resolution'] ?? '—')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($disputes === []): ?><tr><td colspan="4" style="color:#94a3b8">No disputes.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
