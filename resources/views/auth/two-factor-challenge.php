<?php
/** @var array<string,array<int,string>> $errors @var string $csrf_token */
$errors = $errors ?? [];
?>
<div class="card">
  <h1>Two-factor verification</h1>
  <p class="sub">Enter the 6-digit code from your authenticator app.</p>

  <form action="/2fa" method="post" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <label for="code">Authentication code</label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9]*" maxlength="6" autofocus>
    <?php if (!empty($errors['code'])): ?><div class="field-error"><?= e($errors['code'][0]) ?></div><?php endif; ?>

    <button type="submit">Verify</button>
  </form>

  <p class="meta"><a href="/logout" onclick="event.preventDefault();this.closest('main').querySelector('form').submit();">Cancel</a></p>
</div>
