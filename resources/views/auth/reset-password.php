<?php
/** @var string $token @var array<string,array<int,string>> $errors @var string $csrf_token */
$errors = $errors ?? [];
?>
<div class="card">
  <h1>Choose a new password</h1>
  <p class="sub">Your new password must be at least 10 characters.</p>

  <form action="/reset-password" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

    <label for="password">New password</label>
    <input type="password" id="password" name="password" autocomplete="new-password" autofocus>
    <?php if (!empty($errors['password'])): ?><div class="field-error"><?= e($errors['password'][0]) ?></div><?php endif; ?>

    <label for="password_confirmation">Confirm new password</label>
    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">

    <button type="submit">Reset password</button>
  </form>
</div>
