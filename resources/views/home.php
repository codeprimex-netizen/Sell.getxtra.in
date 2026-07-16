<?php
/**
 * Storefront landing page.
 *
 * @var array<int,array<string,mixed>> $featured
 * @var array<int,array<string,mixed>> $categories
 * @var string $app_name
 */
$featured = $featured ?? [];
$categories = $categories ?? [];
?>
<section style="text-align:center;padding:2.5rem 0 1.5rem">
  <span class="pill" style="background:#1e293b;color:#38bdf8"><?= e($app_name) ?></span>
  <h1 style="font-size:2.4rem;line-height:1.15;margin:1rem 0 .5rem;background:linear-gradient(90deg,#38bdf8,#818cf8);-webkit-background-clip:text;background-clip:text;color:transparent">
    <?= e(__('app.tagline')) ?>
  </h1>
  <p class="sub" style="max-width:520px;margin:0 auto 1.4rem">
    Themes, plugins, templates and code kits from verified sellers — instant, secure downloads.
  </p>
  <div>
    <a href="/products"><button type="button" style="width:auto;padding:.7rem 1.4rem"><?= e(__('catalog.title')) ?></button></a>
    <a href="/register" style="margin-left:.6rem">
      <button type="button" class="ghost" style="width:auto;padding:.7rem 1.4rem"><?= e(__('nav.register')) ?></button>
    </a>
  </div>
</section>

<?php if ($categories !== []): ?>
<section style="margin:1rem 0 1.75rem;text-align:center">
  <a href="/products" class="pill">All</a>
  <?php foreach ($categories as $c): ?>
    <a href="/products?category=<?= e((string) $c['slug']) ?>" class="pill"><?= e((string) $c['name']) ?></a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h1 style="font-size:1.3rem">Featured products</h1>
    <a href="/products" style="font-size:.88rem"><?= e(__('catalog.title')) ?> &rarr;</a>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-top:1rem">
    <?php foreach ($featured as $p): ?>
      <a href="/product/<?= e((string) $p['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;overflow:hidden">
          <div style="height:120px;background:#1e293b center/cover no-repeat<?= !empty($p['thumbnail_url']) ? ";background-image:url('" . e((string) $p['thumbnail_url']) . "')" : '' ?>">
            <?php if ((int) ($p['is_featured'] ?? 0) === 1): ?>
              <span class="pill" style="margin:.5rem;display:inline-block;background:#f59e0b;color:#111827">Featured</span>
            <?php endif; ?>
          </div>
          <div style="padding:.75rem">
            <div style="font-weight:600;font-size:.92rem"><?= e((string) $p['title']) ?></div>
            <div style="color:#94a3b8;font-size:.8rem;margin:.3rem 0"><?= e(mb_substr((string) ($p['short_desc'] ?? ''), 0, 60)) ?></div>
            <div style="color:#38bdf8;font-weight:700"><?= e(number_format((float) $p['base_price'], 2)) ?> <?= e((string) $p['currency']) ?></div>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if ($featured === []): ?>
      <p style="color:#94a3b8"><?= e(__('catalog.no_items')) ?></p>
    <?php endif; ?>
  </div>
</div>
