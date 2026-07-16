<?php
/**
 * @var string $mode  'create'|'edit'
 * @var array<string,mixed>|null $product
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,string> $difficulties
 * @var string $csrf_token
 */
$mode = $mode ?? 'create';
$product = $product ?? [];
$errors = $errors ?? [];
$old = $old ?? [];
$categories = $categories ?? [];
$difficulties = $difficulties ?? [];
$tiers = $tiers ?? [];
$versions = $versions ?? [];
$screenshots = $screenshots ?? [];
$tagsValue = $tags_value ?? '';
$isEdit = $mode === 'edit' && !empty($product);
$action = $isEdit ? '/seller/products/' . (int) $product['id'] : '/seller/products';
$val = static fn (string $key, mixed $default = '') => e((string) ($old[$key] ?? ($product[$key] ?? $default)));
$status = (string) ($product['status'] ?? 'draft');
?>
<div class="card wide">
  <h1><?= $isEdit ? 'Edit product' : 'New product' ?></h1>
  <?php if ($isEdit): ?>
    <p class="sub">Status: <strong><?= e(ucwords(str_replace('_', ' ', $status))) ?></strong>
      <?php if (!empty($product['reject_reason'])): ?>
        &mdash; <span style="color:#fca5a5">Reason: <?= e((string) $product['reject_reason']) ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <form action="<?= e($action) ?>" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

    <label>Title</label>
    <input type="text" name="title" value="<?= $val('title') ?>">
    <?php if (!empty($errors['title'])): ?><div class="field-error"><?= e($errors['title'][0]) ?></div><?php endif; ?>

    <label>Short description</label>
    <input type="text" name="short_desc" value="<?= $val('short_desc') ?>">

    <label>Full description</label>
    <textarea name="description" rows="5" style="width:100%;padding:.7rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0"><?= $val('description') ?></textarea>

    <label>Category</label>
    <select name="category_id" style="width:100%;padding:.6rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0">
      <option value="">— Select —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (string) ($product['category_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Tech stack (comma separated)</label>
    <input type="text" name="tech_stack" value="<?= $val('tech_stack') ?>">

    <label>Tags (comma separated)</label>
    <input type="text" name="tags" value="<?= e($tagsValue) ?>">

    <label>Difficulty</label>
    <select name="difficulty" style="width:100%;padding:.6rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0">
      <option value="">— Select —</option>
      <?php foreach ($difficulties as $d): ?>
        <option value="<?= e($d) ?>" <?= (string) ($product['difficulty'] ?? '') === $d ? 'selected' : '' ?>><?= e(ucfirst($d)) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Base price (INR)</label>
    <input type="text" name="base_price" value="<?= $val('base_price', '0.00') ?>">
    <?php if (!empty($errors['base_price'])): ?><div class="field-error"><?= e($errors['base_price'][0]) ?></div><?php endif; ?>

    <label>Demo URL</label>
    <input type="text" name="demo_url" value="<?= $val('demo_url') ?>">

    <label>Thumbnail image (jpg/png/webp, max 5MB)</label>
    <input type="file" name="thumbnail" accept="image/*">

    <button type="submit"><?= $isEdit ? 'Save changes' : 'Create draft' ?></button>
  </form>

  <?php if ($isEdit): ?>
    <hr style="border-color:#1e293b;margin:1.75rem 0">
    <h1 style="font-size:1.15rem">Screenshots &amp; gallery</h1>
    <p class="sub">Add preview images buyers see on the product page (jpg/png/webp, max 5MB each).</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin:.5rem 0 1rem">
      <?php foreach ($screenshots as $s): ?>
        <div style="background:#0b1220;border:1px solid #1e293b;border-radius:9px;overflow:hidden">
          <img src="<?= e((string) $s['url']) ?>" alt="Screenshot" style="width:100%;height:100px;object-fit:cover;display:block">
          <form action="/seller/products/<?= (int) $product['id'] ?>/screenshots/<?= (int) $s['id'] ?>/delete" method="post" style="margin:0">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button type="submit" style="width:100%;margin:0;border-radius:0;background:#7f1d1d;padding:.3rem;font-size:.8rem">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if ($screenshots === []): ?><p style="color:#94a3b8;grid-column:1/-1">No screenshots yet.</p><?php endif; ?>
    </div>
    <form action="/seller/products/<?= (int) $product['id'] ?>/screenshots" method="post" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <input type="file" name="screenshot" accept="image/*">
      <button class="ghost" type="submit">Add screenshot</button>
    </form>

    <hr style="border-color:#1e293b;margin:1.75rem 0">
    <h1 style="font-size:1.15rem">Versions</h1>
    <table>
      <thead><tr><th>Version</th><th>Scan</th><th>Current</th><th>Uploaded</th></tr></thead>
      <tbody>
        <?php foreach ($versions as $v): ?>
          <tr>
            <td><?= e((string) $v['version_number']) ?></td>
            <td><?= e((string) $v['scan_status']) ?></td>
            <td><?= (int) $v['is_current'] === 1 ? 'Yes' : '' ?></td>
            <td><?= e((string) $v['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($versions === []): ?><tr><td colspan="4" style="color:#94a3b8">No versions uploaded.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <form action="/seller/products/<?= (int) $product['id'] ?>/versions" method="post" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <label>Version number (e.g. 1.0.0)</label>
      <input type="text" name="version_number" value="1.0.0">
      <label>Changelog</label>
      <input type="text" name="changelog" placeholder="Initial release">
      <label>Deliverable (.zip, max 200MB)</label>
      <input type="file" name="deliverable" accept=".zip">
      <button class="ghost" type="submit">Upload version</button>
    </form>

    <hr style="border-color:#1e293b;margin:1.75rem 0">
    <div style="display:flex;gap:.75rem">
      <form action="/seller/products/<?= (int) $product['id'] ?>/submit" method="post" style="flex:1">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <button type="submit">Submit for review</button>
      </form>
      <form action="/seller/products/<?= (int) $product['id'] ?>/archive" method="post" style="flex:1">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <button class="ghost" type="submit">Archive</button>
      </form>
    </div>
  <?php endif; ?>
</div>
