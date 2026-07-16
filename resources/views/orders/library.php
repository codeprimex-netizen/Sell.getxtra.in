<?php
/** @var array<int,array<string,mixed>> $entitlements */
$entitlements = $entitlements ?? [];
?>
<div class="card wide">
  <h1>My downloads</h1>
  <p class="sub">Products you own. Downloads are served over secure, expiring links.</p>

  <table>
    <thead><tr><th>Product</th><th>License key</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($entitlements as $e): ?>
        <tr>
          <td><a href="/product/<?= e((string) $e['slug']) ?>"><?= e((string) $e['title']) ?></a></td>
          <td><span class="mono" style="padding:.2rem .4rem"><?= e((string) $e['license_key']) ?></span></td>
          <td><?= e((string) $e['status']) ?></td>
          <td>
            <?php if (($e['status'] ?? '') === 'active'): ?>
              <a href="/downloads/<?= (int) $e['id'] ?>"><button type="button" style="width:auto;margin:0;padding:.3rem .8rem">Download</button></a>
            <?php else: ?>
              <span style="color:#94a3b8">Revoked</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($entitlements === []): ?><tr><td colspan="4" style="color:#94a3b8">You haven't purchased anything yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
