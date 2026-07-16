<?php
/** @var array<string,array<int,string>> $errors @var array<string,mixed> $old @var string $csrf_token */
$errors = $errors ?? [];
$old = $old ?? [];
?>
<div class="card">
  <h1>Create your account</h1>
  <p class="sub">Join Sell.getxtra.in to buy and sell digital products.</p>

  <form action="/register" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <label for="name">Full name</label>
    <input type="text" id="name" name="name" value="<?= e((string)($old['name'] ?? '')) ?>" autofocus>
    <?php if (!empty($errors['name'])): ?><div class="field-error"><?= e($errors['name'][0]) ?></div><?php endif; ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e((string)($old['email'] ?? '')) ?>">
    <?php if (!empty($errors['email'])): ?><div class="field-error"><?= e($errors['email'][0]) ?></div><?php endif; ?>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="new-password">
    <?php if (!empty($errors['password'])): ?><div class="field-error"><?= e($errors['password'][0]) ?></div><?php endif; ?>

    <label for="password_confirmation">Confirm password</label>
    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">

    <label class="check">
      <input type="checkbox" name="terms" value="1">
      <span>I agree to the Terms of Service and Privacy Policy.</span>
    </label>
    <?php if (!empty($errors['terms'])): ?><div class="field-error"><?= e($errors['terms'][0]) ?></div><?php endif; ?>

    <button type="submit">Create account</button>
  </form>

  <p class="meta">Already have an account? <a href="/login">Sign in</a></p>
</div>
