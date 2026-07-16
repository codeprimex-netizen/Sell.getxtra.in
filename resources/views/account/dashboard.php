<?php
/** @var array<string,mixed>|null $user @var array<int,string> $roles @var string $csrf_token */
$user = $user ?? [];
$roles = $roles ?? [];
$twoFactor = (int) ($user['two_factor_enabled'] ?? 0) === 1;
$verified = !empty($user['email_verified_at']);
?>
<div class="card">
  <h1>Welcome, <?= e((string) ($user['name'] ?? 'there')) ?></h1>
  <p class="sub"><?= e((string) ($user['email'] ?? '')) ?></p>

  <div style="margin:.5rem 0 1rem">
    <?php foreach ($roles as $role): ?>
      <span class="pill"><?= e($role) ?></span>
    <?php endforeach; ?>
    <?php if ($roles === []): ?><span class="pill">buyer</span><?php endif; ?>
  </div>

  <table>
    <tr><th>Email verified</th><td><?= $verified ? 'Yes' : 'No' ?></td></tr>
    <tr><th>Two-factor auth</th><td><?= $twoFactor ? 'Enabled' : 'Disabled' ?></td></tr>
    <tr><th>Account status</th><td><?= e((string) ($user['status'] ?? 'active')) ?></td></tr>
  </table>

  <?php if ($twoFactor): ?>
    <form action="/2fa/disable" method="post">
      <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
      <button class="ghost" type="submit">Disable two-factor</button>
    </form>
  <?php else: ?>
    <a href="/2fa/setup"><button type="button">Enable two-factor authentication</button></a>
  <?php endif; ?>
</div>
