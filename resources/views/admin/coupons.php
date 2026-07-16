<?php
/** @var array<int,array<string,mixed>> $coupons @var string $csrf_token */
$coupons = $coupons ?? [];
?>
<div class="card wide">
  <h1>Coupons</h1>

  <form action="/admin/coupons" method="post" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <div><label>Code</label><input type="text" name="code" placeholder="SAVE20"></div>
    <div><label>Type</label>
      <select name="type" style="padding:.6rem;border-radius:9px;background:#0b1220;color:#e2e8f0;border:1px solid #334155">
        <option value="percent">percent</option><option value="fixed">fixed</option>
      </select>
    </div>
    <div><label>Value</label><input type="text" name="value" style="max-width:90px"></div>
    <div><label>Min order</label><input type="text" name="min_order" style="max-width:90px"></div>
    <div><label>Max uses</label><input type="text" name="max_uses" style="max-width:90px"></div>
    <button type="submit" style="width:auto;margin:0">Create</button>
  </form>

  <table>
    <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Used</th><th>Active</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($coupons as $c): ?>
        <tr>
          <td><span class="mono" style="padding:.15rem .4rem"><?= e((string) $c['code']) ?></span></td>
          <td><?= e((string) $c['type']) ?></td>
          <td><?= e((string) $c['value']) ?></td>
          <td><?= (int) ($c['used_count'] ?? 0) ?><?= isset($c['max_uses']) && $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
          <td><?= (int) ($c['is_active'] ?? 1) === 1 ? 'Yes' : 'No' ?></td>
          <td>
            <form action="/admin/coupons/<?= (int) $c['id'] ?>/toggle" method="post" style="margin:0">
              <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
              <input type="hidden" name="active" value="<?= (int) ($c['is_active'] ?? 1) === 1 ? '0' : '1' ?>">
              <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#1e293b;color:#e2e8f0"><?= (int) ($c['is_active'] ?? 1) === 1 ? 'Disable' : 'Enable' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($coupons === []): ?><tr><td colspan="6" style="color:#94a3b8">No coupons.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
