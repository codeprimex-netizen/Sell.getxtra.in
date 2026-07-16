<?php
/** @var array<int,array<string,mixed>> $entitlements */
$entitlements = $entitlements ?? [];
?>
<div class="card wide">
  <h1>My downloads</h1>
  <p class="sub">Products you own. Secure downloads arrive in Phase 6.</p>

  <table>
    <thead><tr><th>Product</th><th>License key</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($entitlements as $e): ?>
        <tr>
          <td><a href="/product/<?= e((string) $e['slug']) ?>"><?= e((string) $e['title']) ?></a></td>
          <td><span class="mono" style="padding:.2rem .4rem"><?= e((string) $e['license_key']) ?></span></td>
          <td><?= e((string) $e['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($entitlements === []): ?><tr><td colspan="3" style="color:#94a3b8">You haven't purchased anything yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
