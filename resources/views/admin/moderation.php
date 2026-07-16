<?php
/** @var array<int,array<string,mixed>> $items @var string $csrf_token */
$items = $items ?? [];
?>
<div class="card wide">
  <h1>Moderation queue</h1>
  <p class="sub">Products awaiting review. Approve to publish, or reject with a reason.</p>

  <table>
    <thead><tr><th>Title</th><th>Status</th><th>Scan</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($items as $p): ?>
        <tr>
          <td>
            <a href="/product/<?= e((string) $p['slug']) ?>"><?= e((string) $p['title']) ?></a>
            <div style="color:#64748b;font-size:.78rem">seller #<?= (int) $p['seller_id'] ?></div>
          </td>
          <td><?= e(ucwords(str_replace('_', ' ', (string) $p['status']))) ?></td>
          <td><?= e((string) ($p['current_scan'] ?? 'none')) ?></td>
          <td>
            <div style="display:flex;gap:.4rem;align-items:center">
              <form action="/admin/moderation/<?= (int) $p['id'] ?>/approve" method="post" style="margin:0">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button type="submit" style="width:auto;margin:0;padding:.3rem .7rem;background:#059669;color:#fff">Approve</button>
              </form>
              <form action="/admin/moderation/<?= (int) $p['id'] ?>/reject" method="post" style="margin:0;display:flex;gap:.3rem">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="text" name="reason" placeholder="reason" style="width:120px;padding:.3rem">
                <button type="submit" style="width:auto;margin:0;padding:.3rem .7rem;background:#3b0d0d;color:#fca5a5">Reject</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($items === []): ?>
        <tr><td colspan="4" style="color:#94a3b8">Nothing awaiting review. 🎉</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
