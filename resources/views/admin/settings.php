<?php
/** @var array<int,array<string,mixed>> $flags @var array<string,mixed> $settings @var string $csrf_token */
$flags = $flags ?? [];
$settings = $settings ?? [];
?>
<div class="card wide">
  <h1>Feature flags</h1>
  <table>
    <thead><tr><th>Flag</th><th>Enabled</th><th>Rollout</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($flags as $f): ?>
        <tr>
          <td><?= e((string) $f['name']) ?></td>
          <td><?= (int) ($f['is_enabled'] ?? 0) === 1 ? 'On' : 'Off' ?></td>
          <td><?= (int) ($f['rollout_percent'] ?? 0) ?>%</td>
          <td>
            <form action="/admin/settings/flag" method="post" style="margin:0">
              <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
              <input type="hidden" name="name" value="<?= e((string) $f['name']) ?>">
              <input type="hidden" name="enabled" value="<?= (int) ($f['is_enabled'] ?? 0) === 1 ? '0' : '1' ?>">
              <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#1e293b;color:#e2e8f0"><?= (int) ($f['is_enabled'] ?? 0) === 1 ? 'Disable' : 'Enable' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($flags === []): ?><tr><td colspan="4" style="color:#94a3b8">No feature flags.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h1 style="font-size:1.15rem;margin-top:1.5rem">Platform settings</h1>
  <form action="/admin/settings/set" method="post" style="display:flex;gap:.5rem">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="text" name="key" placeholder="key (e.g. support_email)">
    <input type="text" name="value" placeholder="value">
    <button type="submit" style="width:auto;margin:0">Save</button>
  </form>
  <table>
    <tbody>
      <?php foreach ($settings as $k => $v): ?>
        <tr><th><?= e((string) $k) ?></th><td><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($settings === []): ?><tr><td style="color:#94a3b8">No settings yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
