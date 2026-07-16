<?php
/** @var array<int,array<string,mixed>> $users @var string $term @var array<int,string> $roles @var string $csrf_token */
$users = $users ?? [];
$roles = $roles ?? [];
?>
<div class="card wide">
  <h1>Users</h1>
  <form action="/admin/users" method="get" style="display:flex;gap:.5rem">
    <input type="text" name="q" value="<?= e($term ?? '') ?>" placeholder="Search name or email">
    <button type="submit" style="width:auto;margin:0">Search</button>
  </form>

  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e((string) $u['name']) ?></td>
          <td><?= e((string) $u['email']) ?></td>
          <td><?= e((string) $u['status']) ?></td>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;align-items:center">
              <?php if (($u['status'] ?? '') === 'suspended'): ?>
                <form action="/admin/users/<?= (int) $u['id'] ?>/activate" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#059669;color:#fff">Activate</button></form>
              <?php else: ?>
                <form action="/admin/users/<?= (int) $u['id'] ?>/suspend" method="post" style="margin:0"><input type="hidden" name="_token" value="<?= e($csrf_token) ?>"><button type="submit" style="width:auto;margin:0;padding:.25rem .6rem;background:#3b0d0d;color:#fca5a5">Suspend</button></form>
              <?php endif; ?>
              <form action="/admin/users/<?= (int) $u['id'] ?>/roles/assign" method="post" style="margin:0;display:flex;gap:.2rem">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <select name="role" style="padding:.25rem;border-radius:6px;background:#0b1220;color:#e2e8f0;border:1px solid #334155">
                  <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" style="width:auto;margin:0;padding:.25rem .6rem">+role</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($users === []): ?><tr><td colspan="4" style="color:#94a3b8">No users found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
