<?php
/**
 * Base layout. Expects: $app_name, $content, and optionally $auth_user,
 * $flash_success, $flash_error. All dynamic values are escaped with e().
 *
 * @var string $app_name
 * @var string $content
 */
$authUser = $auth_user ?? null;
$flashSuccess = $flash_success ?? null;
$flashError = $flash_error ?? null;
?>
<!doctype html>
<html lang="<?= e($locale ?? 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? $app_name) ?></title>
  <?php /* Advanced SEO: canonical, robots, hreflang, Open Graph, Twitter, JSON-LD (@graph). */ ?>
  <?= $seo_head ?? '' ?>
  <style>
    :root{color-scheme:dark}
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}
    a{color:#38bdf8;text-decoration:none}
    a:hover{text-decoration:underline}
    header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;border-bottom:1px solid #1e293b}
    header .brand{font-weight:700;font-size:1.1rem;background:linear-gradient(90deg,#38bdf8,#818cf8);-webkit-background-clip:text;background-clip:text;color:transparent}
    header nav a{margin-left:1rem;color:#94a3b8;font-size:.92rem}
    main{max-width:460px;margin:2.5rem auto;padding:0 1.25rem}
    .wide{max-width:760px}
    .card{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:1.75rem}
    h1{font-size:1.5rem;margin:0 0 .35rem}
    .sub{color:#94a3b8;margin:0 0 1.4rem;font-size:.92rem}
    label{display:block;font-size:.85rem;color:#cbd5e1;margin:.9rem 0 .35rem}
    input[type=text],input[type=email],input[type=password]{width:100%;padding:.7rem .8rem;border-radius:9px;border:1px solid #334155;background:#0b1220;color:#e2e8f0;font-size:.95rem}
    input:focus{outline:none;border-color:#38bdf8}
    .check{display:flex;align-items:flex-start;gap:.5rem;margin:1rem 0;font-size:.85rem;color:#cbd5e1}
    button{width:100%;margin-top:1.3rem;padding:.75rem;border:0;border-radius:9px;background:linear-gradient(90deg,#38bdf8,#6366f1);color:#04121f;font-weight:700;font-size:.95rem;cursor:pointer}
    button.ghost{background:#1e293b;color:#e2e8f0;font-weight:600}
    .meta{margin-top:1.1rem;font-size:.85rem;color:#94a3b8;text-align:center}
    .alert{padding:.7rem .85rem;border-radius:9px;font-size:.88rem;margin-bottom:1rem;word-break:break-word}
    .alert.ok{background:#052e2b;border:1px solid #134e4a;color:#5eead4}
    .alert.err{background:#3b0d0d;border:1px solid #7f1d1d;color:#fca5a5}
    .field-error{color:#fca5a5;font-size:.8rem;margin-top:.3rem}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#0b1220;border:1px solid #334155;border-radius:8px;padding:.6rem;word-break:break-all;font-size:.82rem}
    table{width:100%;border-collapse:collapse;font-size:.88rem;margin-top:1rem}
    th,td{text-align:left;padding:.55rem .4rem;border-bottom:1px solid #1e293b}
    .pill{display:inline-block;background:#1e293b;color:#93c5fd;border-radius:999px;padding:.15rem .6rem;font-size:.75rem;margin-right:.3rem}
  </style>
</head>
<body>
  <header>
    <a class="brand" href="/"><?= e($app_name) ?></a>
    <nav>
      <a href="/products">Browse</a>
      <a href="/faq">Help</a>
      <?php if ($authUser !== null): ?>
        <a href="/dashboard">Dashboard</a>
        <a href="/seller/products">Sell</a>
        <form action="/logout" method="post" style="display:inline">
          <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
          <button type="submit" style="width:auto;margin:0;padding:.3rem .8rem;background:#1e293b;color:#e2e8f0;font-weight:600">Log out</button>
        </form>
      <?php else: ?>
        <a href="/login">Log in</a>
        <a href="/register">Sign up</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="<?= e($wide ?? '') ? 'wide' : '' ?>">
    <?php if ($flashSuccess): ?><div class="alert ok"><?= e((string) $flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert err"><?= e((string) $flashError) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</body>
</html>
