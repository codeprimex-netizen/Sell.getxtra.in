<?php
/**
 * Public FAQ / help page. The matching FAQPage JSON-LD is emitted from the
 * shared SEO head ($seo_head), so this view only renders the human-readable
 * questions and answers.
 *
 * @var array<int,array{q:string,a:string}> $faqs
 * @var string $app_name
 */
$faqs = $faqs ?? [];
?>
<section style="text-align:center;padding:1.5rem 0 .5rem">
  <span class="pill" style="background:#1e293b;color:#38bdf8">Help centre</span>
  <h1 style="font-size:2rem;margin:.8rem 0 .4rem">Frequently asked questions</h1>
  <p class="sub" style="max-width:560px;margin:0 auto 1rem">
    Everything you need to know about buying and selling digital products on <?= e($app_name) ?>.
  </p>
</section>

<div class="card wide">
  <?php foreach ($faqs as $i => $faq): ?>
    <details<?= $i === 0 ? ' open' : '' ?> style="border-bottom:1px solid #1e293b;padding:.9rem 0">
      <summary style="cursor:pointer;font-weight:600;font-size:1rem;color:#e2e8f0;list-style:none">
        <?= e((string) $faq['q']) ?>
      </summary>
      <p style="color:#94a3b8;font-size:.92rem;line-height:1.55;margin:.7rem 0 0">
        <?= e((string) $faq['a']) ?>
      </p>
    </details>
  <?php endforeach; ?>

  <?php if ($faqs === []): ?>
    <p style="color:#94a3b8">No questions available yet.</p>
  <?php endif; ?>
</div>

<section style="text-align:center;margin:1.5rem 0">
  <p class="sub">Still need help?</p>
  <a href="/products"><button type="button" style="width:auto;padding:.7rem 1.4rem">Browse products</button></a>
  <a href="/register" style="margin-left:.5rem">
    <button type="button" class="ghost" style="width:auto;padding:.7rem 1.4rem">Create an account</button>
  </a>
</section>
