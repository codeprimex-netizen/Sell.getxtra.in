<?php
/** @var string $secret @var string $uri @var array<string,array<int,string>> $errors @var string $csrf_token */
$errors = $errors ?? [];
$uri = $uri ?? '';
?>
<div class="card">
  <h1>Set up two-factor authentication</h1>
  <p class="sub">Add this account to Google Authenticator, Authy, or a compatible app, then confirm with a code.</p>

  <label>Secret key (enter manually if you can't scan)</label>
  <div class="mono"><?= e($secret ?? '') ?></div>

  <?php if ($uri !== ''): ?>
    <label style="margin-top:1rem">Provisioning URI</label>
    <div class="mono"><?= e($uri) ?></div>
  <?php endif; ?>

  <form action="/2fa/confirm" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <label for="code">Enter the 6-digit code to confirm</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus>
    <?php if (!empty($errors['code'])): ?><div class="field-error"><?= e($errors['code'][0]) ?></div><?php endif; ?>

    <button type="submit">Enable two-factor</button>
  </form>
</div>
