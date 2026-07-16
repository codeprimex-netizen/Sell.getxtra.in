<?php
/** @var bool $ok */
$ok = $ok ?? false;
?>
<div class="card">
  <?php if ($ok): ?>
    <h1>You're unsubscribed</h1>
    <p class="sub">You will no longer receive marketing and transactional emails from Sell.getxtra.in. You can re-enable email in your account notification settings at any time.</p>
  <?php else: ?>
    <h1>Link expired or invalid</h1>
    <p class="sub">We couldn't process this unsubscribe link. It may have already been used or is no longer valid.</p>
  <?php endif; ?>
  <p><a href="/">Return to Sell.getxtra.in</a></p>
</div>
