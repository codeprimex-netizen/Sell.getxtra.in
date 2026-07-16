<?php
/** @var array<int,array<string,mixed>> $keys @var array<int,string> $all_scopes @var string|null $new_token @var string $csrf_token */
$keys = $keys ?? [];
$all_scopes = $all_scopes ?? [];
?>
<div class="card">
  <h1>API keys</h1>
  <p class="sub">Programmatic access to the Code.getxtra.in API. Authenticate requests with <code>Authorization: Bearer &lt;token&gt;</code>.</p>

  <?php if (!empty($new_token)): ?>
    <div class="notice" style="background:#052e2b;border:1px solid #0d9488;padding:1rem;border-radius:8px;margin:1rem 0">
      <strong>Your new API token</strong>
      <p style="margin:.4rem 0;color:#94a3b8">Copy it now — for security it will not be shown again.</p>
      <code style="display:block;word-break:break-all;background:#0f172a;padding:.6rem;border-radius:6px"><?= e((string) $new_token) ?></code>
    </div>
  <?php endif; ?>

  <h2>Create a key</h2>
  <form action="/account/api-keys" method="post">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <label>Name
      <input type="text" name="name" maxlength="120" placeholder="e.g. Reporting integration" required>
    </label>
    <fieldset style="border:1px solid #1e293b;border-radius:8px;padding:.75rem;margin:.75rem 0">
      <legend>Scopes</legend>
      <?php foreach ($all_scopes as $scope): ?>
        <label style="display:block;font-weight:400">
          <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>"> <?= e($scope) ?>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <button type="submit" style="width:auto;padding:.4rem 1rem">Generate key</button>
  </form>

  <h2 style="margin-top:1.5rem">Your keys</h2>
  <table>
    <thead><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Last used</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($keys as $k): ?>
        <tr>
          <td><?= e((string) $k['name']) ?></td>
          <td><code><?= e((string) $k['prefix']) ?></code></td>
          <td><?= e((string) ($k['scopes'] ?? '')) ?: '<span style="color:#64748b">none</span>' ?></td>
          <td><?= e((string) ($k['last_used_at'] ?? 'never')) ?></td>
          <td>
            <form action="/account/api-keys/revoke" method="post" style="display:inline">
              <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
              <input type="hidden" name="id" value="<?= e((string) $k['id']) ?>">
              <button type="submit" style="width:auto;padding:.25rem .7rem;background:#7f1d1d">Revoke</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($keys === []): ?>
        <tr><td colspan="5" style="color:#94a3b8">You have no API keys yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
