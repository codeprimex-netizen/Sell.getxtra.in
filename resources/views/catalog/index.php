<?php
/**
 * @var array<int,array<string,mixed>> $products
 * @var array<int,array<string,mixed>> $categories
 * @var string $active_category
 * @var int $page
 */
$products = $products ?? [];
$categories = $categories ?? [];
$active = $active_category ?? '';
$page = $page ?? 1;
?>
<div class="card wide">
  <h1>Browse products</h1>
  <p class="sub">Discover digital products from verified sellers.</p>

  <div style="margin-bottom:1rem">
    <a href="/products" class="pill" style="<?= $active === '' ? 'background:#38bdf8;color:#04121f' : '' ?>">All</a>
    <?php foreach ($categories as $c): ?>
      <a href="/products?category=<?= e((string) $c['slug']) ?>" class="pill" style="<?= $active === $c['slug'] ? 'background:#38bdf8;color:#04121f' : '' ?>"><?= e((string) $c['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
    <?php foreach ($products as $p): ?>
      <a href="/product/<?= e((string) $p['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;overflow:hidden">
          <div style="height:120px;background:#1e293b center/cover no-repeat<?= !empty($p['thumbnail_url']) ? ";background-image:url('" . e((string) $p['thumbnail_url']) . "')" : '' ?>"></div>
          <div style="padding:.75rem">
            <div style="font-weight:600;font-size:.92rem"><?= e((string) $p['title']) ?></div>
            <div style="color:#94a3b8;font-size:.8rem;margin:.3rem 0"><?= e(mb_substr((string) ($p['short_desc'] ?? ''), 0, 60)) ?></div>
            <div style="color:#38bdf8;font-weight:700"><?= e(number_format((float) $p['base_price'], 2)) ?> <?= e((string) $p['currency']) ?></div>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if ($products === []): ?>
      <p style="color:#94a3b8">No products found in this category yet.</p>
    <?php endif; ?>
  </div>
</div>
