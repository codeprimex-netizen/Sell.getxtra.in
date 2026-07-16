<?php
/** @var array<string,array<int,string>> $errors @var array<string,mixed> $old @var string $csrf_token */
$errors = $errors ?? [];
$old = $old ?? [];
?>
<div class="card">
  <h1>Welcome back</h1>
  <p class="sub">Sign in to your Code.getxtra.in account.</p>

  <?php if (!empty($errors['email'])): ?>
    <div class="alert err"><?= e($errors['email'][0]) ?></div>
  <?php endif; ?>

  <form action="/login" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e((string)($old['email'] ?? '')) ?>" autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password">

    <label class="check">
      <input type="checkbox" name="remember" value="1"><span>Remember me</span>
    </label>

    <button type="submit">Sign in</button>
  </form>

  <p class="meta"><a href="/forgot-password">Forgot your password?</a></p>
  <p class="meta">New here? <a href="/register">Create an account</a></p>
</div>
