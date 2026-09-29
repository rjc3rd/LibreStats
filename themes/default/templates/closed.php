<?php /* Shown instead of the dashboard when config.php has 'dashboard' => false. No login, no forms. */ ls_render('head', ['title' => 'LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?></span>
  <h1>The dashboard is turned off</h1>
  <p>This server is still counting visits, but its own dashboard isn't available right now.</p>
</div></main>
<?php ls_render('foot'); ?>
