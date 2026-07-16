<?php
/**
 * @var \App\Domain\Catalog\SearchResult $result
 * @var \App\Domain\Catalog\SearchCriteria $criteria
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,string> $sorts
 */
$categories = $categories ?? [];
$sorts = $sorts ?? [];
$items = $result->items;
$q = $criteria->query;
$sortLabels = [
    'relevance' => 'Relevance', 'newest' => 'Newest', 'price_asc' => 'Price: low to high',
    'price_desc' => 'Price: high to low', 'rating' => 'Top rated', 'popular' => 'Most popular',
];
?>
<div class="card wide">
  <h1>Search</h1>

  <form action="/search" method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
    <div style="flex:2;min-width:200px">
      <label>Keywords</label>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. laravel dashboard">
    </div>
    <div style="flex:1;min-width:130px">
      <label>Category</label>
      <select name="category_id" style="width:100%;padding:.6rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0">
        <option value="">All</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $criteria->categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="min-width:90px">
      <label>Min price</label>
      <input type="text" name="price_min" value="<?= e((string) ($criteria->priceMin ?? '')) ?>">
    </div>
    <div style="min-width:90px">
      <label>Max price</label>
      <input type="text" name="price_max" value="<?= e((string) ($criteria->priceMax ?? '')) ?>">
    </div>
    <div style="min-width:150px">
      <label>Sort</label>
      <select name="sort" style="width:100%;padding:.6rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0">
        <?php foreach ($sorts as $s): ?>
          <option value="<?= e($s) ?>" <?= $criteria->sort === $s ? 'selected' : '' ?>><?= e($sortLabels[$s] ?? $s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" style="width:auto;margin:0">Search</button>
  </form>

  <p class="sub" style="margin-top:1rem"><?= (int) $result->total ?> result(s)<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?></p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
    <?php foreach ($items as $p): ?>
      <a href="/product/<?= e((string) $p['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="background:#0b1220;border:1px solid #1e293b;border-radius:10px;overflow:hidden">
          <div style="height:110px;background:#1e293b center/cover no-repeat<?= !empty($p['thumbnail_url']) ? ";background-image:url('" . e((string) $p['thumbnail_url']) . "')" : '' ?>"></div>
          <div style="padding:.7rem">
            <div style="font-weight:600;font-size:.9rem"><?= e((string) $p['title']) ?></div>
            <div style="color:#94a3b8;font-size:.78rem;margin:.25rem 0"><?= e(mb_substr((string) ($p['short_desc'] ?? ''), 0, 55)) ?></div>
            <div style="color:#38bdf8;font-weight:700"><?= e(number_format((float) $p['base_price'], 2)) ?> <?= e((string) ($p['currency'] ?? 'INR')) ?></div>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if ($items === []): ?><p style="color:#94a3b8">No products match your search.</p><?php endif; ?>
  </div>

  <?php if ($result->pages() > 1): ?>
    <div style="margin-top:1.25rem;display:flex;gap:.5rem;align-items:center">
      <?php
      $qs = static function (array $over) use ($criteria): string {
          return http_build_query(array_merge([
              'q' => $criteria->query, 'category_id' => $criteria->categoryId,
              'price_min' => $criteria->priceMin, 'price_max' => $criteria->priceMax,
              'sort' => $criteria->sort,
          ], $over));
      };
      ?>
      <?php if ($result->hasPrev()): ?><a href="/search?<?= e($qs(['page' => $result->page - 1])) ?>">&larr; Prev</a><?php endif; ?>
      <span style="color:#94a3b8">Page <?= $result->page ?> of <?= $result->pages() ?></span>
      <?php if ($result->hasNext()): ?><a href="/search?<?= e($qs(['page' => $result->page + 1])) ?>">Next &rarr;</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
