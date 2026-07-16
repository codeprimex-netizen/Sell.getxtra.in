<?php
/** @var array<int,array<string,mixed>> $categories @var string $csrf_token */
$categories = $categories ?? [];
?>
<div class="card wide">
  <h1>Categories</h1>

  <form action="/admin/categories" method="post" style="display:flex;gap:.5rem">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="text" name="name" placeholder="New category name">
    <button type="submit" style="width:auto;margin:0">Add</button>
  </form>

  <table>
    <thead><tr><th>Name</th><th>Slug</th><th>Active</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><?= e((string) $c['name']) ?></td>
          <td><?= e((string) $c['slug']) ?></td>
          <td><?= (int) ($c['is_active'] ?? 1) === 1 ? 'Yes' : 'No' ?></td>
          <td>
            <div style="display:flex;gap:.3rem">
              <form action="/admin/categories/<?= (int) $c['id'] ?>/toggle" method="post" style="margin:0">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="active" value="<?= (int) ($c['is_active'] ?? 1) === 1 ? '0' : '1' ?>">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#1e293b;color:#e2e8f0"><?= (int) ($c['is_active'] ?? 1) === 1 ? 'Disable' : 'Enable' ?></button>
              </form>
              <form action="/admin/categories/<?= (int) $c['id'] ?>/delete" method="post" style="margin:0">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#3b0d0d;color:#fca5a5">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($categories === []): ?><tr><td colspan="4" style="color:#94a3b8">No categories.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
