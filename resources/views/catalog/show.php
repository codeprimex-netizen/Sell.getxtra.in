<?php
/**
 * @var array<string,mixed> $product
 * @var array<int,array<string,mixed>> $tiers
 * @var array<int,array<string,mixed>> $versions
 * @var array<int,array<string,mixed>> $screenshots
 * @var array<int,string> $tags
 * @var bool $purchasable
 */
$product = $product ?? [];
$tiers = $tiers ?? [];
$versions = $versions ?? [];
$tags = $tags ?? [];
$purchasable = $purchasable ?? false;
?>
<div class="card wide">
  <h1><?= e((string) $product['title']) ?></h1>
  <p class="sub"><?= e((string) ($product['short_desc'] ?? '')) ?></p>

  <?php if (!empty($product['thumbnail_url'])): ?>
    <img src="<?= e((string) $product['thumbnail_url']) ?>" alt="" style="max-width:100%;border-radius:10px;margin-bottom:1rem">
  <?php endif; ?>

  <div style="margin:.4rem 0 1rem">
    <?php foreach ($tags as $t): ?><span class="pill"><?= e($t) ?></span><?php endforeach; ?>
  </div>

  <div style="line-height:1.7;color:#cbd5e1"><?= $product['description'] ?? '' ?></div>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Pricing</h1>
  <table>
    <thead><tr><th>License</th><th>Price</th></tr></thead>
    <tbody>
      <?php foreach ($tiers as $t): ?>
        <tr>
          <td><?= e((string) $t['name']) ?></td>
          <td><?= e(number_format((float) ($t['sale_price'] ?? $t['price']), 2)) ?> <?= e((string) $product['currency']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($purchasable): ?>
    <button type="button">Buy now (checkout in Phase 5)</button>
  <?php else: ?>
    <p style="color:#94a3b8">This product is not available for purchase yet.</p>
  <?php endif; ?>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Version history</h1>
  <table>
    <thead><tr><th>Version</th><th>Changelog</th><th>Released</th></tr></thead>
    <tbody>
      <?php foreach ($versions as $v): ?>
        <tr>
          <td><?= e((string) $v['version_number']) ?></td>
          <td><?= e((string) ($v['changelog'] ?? '')) ?></td>
          <td><?= e((string) $v['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($versions === []): ?><tr><td colspan="3" style="color:#94a3b8">No versions.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
