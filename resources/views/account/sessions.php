<?php
/** @var array<int,array<string,mixed>> $sessions @var string $current_id @var string $csrf_token */
$sessions = $sessions ?? [];
$currentId = $current_id ?? '';
?>
<div class="card">
  <h1>Active sessions</h1>
  <p class="sub">Devices currently signed in to your account.</p>

  <table>
    <thead><tr><th>IP</th><th>Device</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= e((string) ($s['ip'] ?? '—')) ?></td>
          <td><?= e(mb_substr((string) ($s['user_agent'] ?? '—'), 0, 40)) ?></td>
          <td><?= e((string) ($s['last_seen_at'] ?? '')) ?><?= ($s['id'] ?? '') === $currentId ? ' (this device)' : '' ?></td>
          <td>
            <?php if (($s['id'] ?? '') !== $currentId): ?>
              <form action="/account/sessions/revoke" method="post" style="display:inline">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="session_id" value="<?= e((string) $s['id']) ?>">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .7rem;background:#3b0d0d;color:#fca5a5;font-weight:600">Revoke</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($sessions === []): ?>
        <tr><td colspan="4" style="color:#94a3b8">No active sessions found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <form action="/account/sessions/revoke-others" method="post">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <button class="ghost" type="submit">Sign out all other sessions</button>
  </form>
</div>
