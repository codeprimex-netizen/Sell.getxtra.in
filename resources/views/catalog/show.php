<?php
/**
 * @var array<string,mixed> $product
 * @var array<int,array<string,mixed>> $tiers
 * @var array<int,array<string,mixed>> $versions
 * @var array<int,string> $tags
 * @var bool $purchasable
 * @var array<int,array<string,mixed>> $reviews
 * @var array<int,array<string,mixed>> $related
 * @var array<int,array<string,mixed>> $recent
 * @var bool $wishlisted
 * @var string $jsonld
 * @var array<string,mixed>|null $auth_user
 * @var string $csrf_token
 */
$product = $product ?? [];
$tiers = $tiers ?? [];
$versions = $versions ?? [];
$tags = $tags ?? [];
$purchasable = $purchasable ?? false;
$reviews = $reviews ?? [];
$related = $related ?? [];
$recent = $recent ?? [];
$wishlisted = $wishlisted ?? false;
$ratingCount = (int) ($product['rating_count'] ?? 0);
$avgRating = (float) ($product['avg_rating'] ?? 0);
$stars = static fn (float $r): string => str_repeat('★', (int) round($r)) . str_repeat('☆', 5 - (int) round($r));
$card = static function (array $p) use ($stars): string {
    $thumb = !empty($p['thumbnail_url']) ? ";background-image:url('" . e((string) $p['thumbnail_url']) . "')" : '';
    return '<a href="/product/' . e((string) $p['slug']) . '" style="text-decoration:none;color:inherit">'
        . '<div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;overflow:hidden">'
        . '<div style="height:90px;background:#1e293b center/cover no-repeat' . $thumb . '"></div>'
        . '<div style="padding:.6rem"><div style="font-weight:600;font-size:.85rem">' . e((string) $p['title']) . '</div>'
        . '<div style="color:#38bdf8;font-weight:700;font-size:.85rem">' . e(number_format((float) $p['base_price'], 2)) . ' ' . e((string) ($p['currency'] ?? 'INR')) . '</div>'
        . '</div></div></a>';
};
?>
<script type="application/ld+json" nonce="<?= e($csp_nonce ?? '') ?>"><?= $jsonld ?? '{}' ?></script>

<div class="card wide">
  <h1><?= e((string) $product['title']) ?></h1>
  <p class="sub"><?= e((string) ($product['short_desc'] ?? '')) ?></p>

  <div style="color:#fbbf24;font-size:1rem">
    <?= $stars($avgRating) ?>
    <span style="color:#94a3b8;font-size:.85rem"><?= $ratingCount > 0 ? number_format($avgRating, 1) . ' (' . $ratingCount . ' reviews)' : 'No reviews yet' ?></span>
  </div>

  <?php if (!empty($product['thumbnail_url'])): ?>
    <img src="<?= e((string) $product['thumbnail_url']) ?>" alt="<?= e((string) $product['title']) ?>" style="max-width:100%;border-radius:10px;margin:1rem 0">
  <?php endif; ?>

  <?php $screenshots = $screenshots ?? []; if ($screenshots !== []): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.6rem;margin:.5rem 0 1rem">
      <?php foreach ($screenshots as $s): ?>
        <a href="<?= e((string) $s['url']) ?>" target="_blank" rel="noopener">
          <img src="<?= e((string) $s['url']) ?>" alt="<?= e((string) $product['title']) ?> screenshot"
               loading="lazy" style="width:100%;height:130px;object-fit:cover;border-radius:9px;border:1px solid #1e293b">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="margin:.4rem 0 1rem">
    <?php foreach ($tags as $t): ?><span class="pill"><?= e($t) ?></span><?php endforeach; ?>
  </div>

  <div style="line-height:1.7;color:#cbd5e1"><?= $product['description'] ?? '' ?></div>

  <div style="display:flex;gap:.6rem;align-items:center;margin-top:1rem">
    <?php if ($purchasable): ?>
      <form action="/cart/add" method="post" style="margin:0">
        <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <button type="submit" style="width:auto;margin:0">Add to cart</button>
      </form>
    <?php else: ?>
      <span style="color:#94a3b8">Not available for purchase yet.</span>
    <?php endif; ?>
    <form action="/wishlist/toggle" method="post" style="margin:0">
      <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
      <button class="ghost" type="submit" style="width:auto;margin:0"><?= $wishlisted ? '♥ Wishlisted' : '♡ Wishlist' ?></button>
    </form>
  </div>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Pricing</h1>
  <table>
    <thead><tr><th>License</th><th>Price</th></tr></thead>
    <tbody>
      <?php foreach ($tiers as $t): ?>
        <tr><td><?= e((string) $t['name']) ?></td><td><?= e(number_format((float) ($t['sale_price'] ?? $t['price']), 2)) ?> <?= e((string) $product['currency']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Version history</h1>
  <table>
    <thead><tr><th>Version</th><th>Changelog</th><th>Released</th></tr></thead>
    <tbody>
      <?php foreach ($versions as $v): ?>
        <tr><td><?= e((string) $v['version_number']) ?></td><td><?= e((string) ($v['changelog'] ?? '')) ?></td><td><?= e((string) $v['created_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($versions === []): ?><tr><td colspan="3" style="color:#94a3b8">No versions.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Reviews</h1>
  <?php foreach ($reviews as $r): ?>
    <div style="border-bottom:1px solid #1e293b;padding:.6rem 0">
      <div style="color:#fbbf24"><?= $stars((float) $r['rating']) ?>
        <span style="color:#e2e8f0;font-weight:600;margin-left:.4rem"><?= e((string) ($r['author_name'] ?? 'User')) ?></span>
        <?php if ((int) ($r['is_verified'] ?? 0) === 1): ?><span class="pill" style="background:#052e2b;color:#5eead4">Verified purchase</span><?php endif; ?>
      </div>
      <?php if (!empty($r['comment'])): ?><div style="color:#cbd5e1;margin-top:.25rem"><?= e((string) $r['comment']) ?></div><?php endif; ?>
      <?php if (!empty($r['seller_reply'])): ?>
        <div style="margin:.4rem 0 0 1rem;color:#94a3b8;font-size:.88rem"><strong>Seller:</strong> <?= e((string) $r['seller_reply']) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($reviews === []): ?><p style="color:#94a3b8">Be the first to review this product.</p><?php endif; ?>

  <?php if (!empty($auth_user)): ?>
    <form action="/product/<?= (int) $product['id'] ?>/reviews" method="post" style="margin-top:1rem">
      <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
      <label>Your rating (1-5)</label>
      <input type="text" name="rating" inputmode="numeric" maxlength="1" style="max-width:80px">
      <label>Your review</label>
      <textarea name="comment" rows="3" style="width:100%;padding:.7rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0"></textarea>
      <button type="submit" style="width:auto">Post review</button>
    </form>
  <?php else: ?>
    <p class="meta"><a href="/login">Sign in</a> to leave a review.</p>
  <?php endif; ?>

  <?php if ($related !== []): ?>
    <h1 style="font-size:1.15rem;margin-top:1.5rem">Related products</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem">
      <?php foreach ($related as $rp): ?><?= $card($rp) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($recent !== []): ?>
    <h1 style="font-size:1.15rem;margin-top:1.5rem">Recently viewed</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem">
      <?php foreach ($recent as $rp): ?><?= $card($rp) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
