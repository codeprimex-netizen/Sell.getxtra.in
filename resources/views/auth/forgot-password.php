<?php
/** @var array<string,array<int,string>> $errors @var array<string,mixed> $old @var string $csrf_token */
$errors = $errors ?? [];
$old = $old ?? [];
?>
<div class="card">
  <h1>Reset your password</h1>
  <p class="sub">Enter your email and we'll send you a reset link.</p>

  <form action="/forgot-password" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e((string)($old['email'] ?? '')) ?>" autofocus>
    <?php if (!empty($errors['email'])): ?><div class="field-error"><?= e($errors['email'][0]) ?></div><?php endif; ?>

    <button type="submit">Send reset link</button>
  </form>

  <p class="meta"><a href="/login">Back to sign in</a></p>
</div>
