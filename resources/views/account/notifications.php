<?php
/** @var array<int,array<string,mixed>> $notifications @var int $unread @var string $csrf_token */
$notifications = $notifications ?? [];
$unread = $unread ?? 0;
?>
<div class="card">
  <h1>Notifications</h1>
  <p class="sub"><?= (int) $unread ?> unread</p>

  <?php if ($unread > 0): ?>
    <form action="/account/notifications/read-all" method="post" style="margin-bottom:1rem">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button class="ghost" type="submit" style="width:auto;margin:0;padding:.35rem .9rem">Mark all as read</button>
    </form>
  <?php endif; ?>

  <table>
    <thead><tr><th>Type</th><th>Details</th><th>When</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($notifications as $n): ?>
        <?php
          $data = $n['data'] ?? [];
          if (is_string($data)) {
              $decoded = json_decode($data, true);
              $data = is_array($decoded) ? $decoded : [];
          }
          $isUnread = ($n['read_at'] ?? null) === null;
        ?>
        <tr style="<?= $isUnread ? 'font-weight:600' : 'color:#94a3b8' ?>">
          <td><?= e((string) str_replace('_', ' ', (string) ($n['type'] ?? 'system'))) ?></td>
          <td>
            <?php foreach ((array) $data as $k => $v): ?>
              <?php if (is_scalar($v)): ?>
                <span style="display:inline-block;margin-right:.75rem"><?= e((string) $k) ?>: <?= e((string) $v) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>
          <td><?= e((string) ($n['created_at'] ?? '')) ?></td>
          <td>
            <?php if ($isUnread): ?>
              <form action="/account/notifications/read" method="post" style="display:inline">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="id" value="<?= e((string) $n['id']) ?>">
                <button type="submit" style="width:auto;margin:0;padding:.25rem .7rem">Mark read</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($notifications === []): ?>
        <tr><td colspan="4" style="color:#94a3b8">You have no notifications yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
