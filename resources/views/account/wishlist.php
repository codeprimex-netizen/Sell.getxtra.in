<?php
/** @var array<int,array<string,mixed>> $products @var string $csrf_token */
$products = $products ?? [];
?>
<div class="card wide">
  <h1>My wishlist</h1>
  <p class="sub">Products you've saved for later.</p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
    <?php foreach ($products as $p): ?>
      <div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;overflow:hidden">
        <a href="/product/<?= e((string) $p['slug']) ?>" style="text-decoration:none;color:inherit">
          <div style="height:110px;background:#1e293b center/cover no-repeat<?= !empty($p['thumbnail_url']) ? ";background-image:url('" . e((string) $p['thumbnail_url']) . "')" : '' ?>"></div>
          <div style="padding:.7rem">
            <div style="font-weight:600;font-size:.9rem"><?= e((string) $p['title']) ?></div>
            <div style="color:#38bdf8;font-weight:700"><?= e(number_format((float) $p['base_price'], 2)) ?> <?= e((string) ($p['currency'] ?? 'INR')) ?></div>
          </div>
        </a>
        <form action="/wishlist/toggle" method="post" style="padding:0 .7rem .7rem">
          <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
          <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
          <button type="submit" style="width:100%;margin:0;padding:.35rem;background:#3b0d0d;color:#fca5a5;font-weight:600">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if ($products === []): ?><p style="color:#94a3b8">Your wishlist is empty. <a href="/products">Browse products</a>.</p><?php endif; ?>
  </div>
</div>
