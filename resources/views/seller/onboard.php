<?php /** @var string $csrf_token */ ?>
<div class="card">
  <h1>Become a seller</h1>
  <p class="sub">Start selling digital products on Code.getxtra.in.</p>
  <form action="/seller/onboard" method="post">
    <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
    <label>Store / display name</label>
    <input type="text" name="display_name" placeholder="Your store name">
    <button type="submit">Start selling</button>
  </form>
</div>
