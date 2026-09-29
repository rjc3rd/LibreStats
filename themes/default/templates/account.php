<?php
/* A viewer's Settings tab: just their own login. Vars: the common page variables. */
$action = h(ls_link($site, $range, $from, $to, ['view' => 'settings']));
ls_render('head', ['title' => 'Account · ' . $site['name'] . ' · LibreStats']);
ls_render('bar', get_defined_vars());
?>
<main class="ls-main">
  <?php ls_render('tabs', get_defined_vars()); ?>

  <div class="ls-grid ls-grid-2">
    <section class="ls-card">
      <h2>Your login</h2>
      <p class="ls-hint">You are logged in as <b><?= h($user['email']) ?></b>. You can look at the numbers of <?= count($sites) === 1 ? 'this website' : 'these ' . count($sites) . ' websites' ?>, and nothing else: settings are for whoever runs this LibreStats.</p>
      <form class="ls-form ls-form-tight" method="post" action="<?= $action ?>">
        <input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Current password<input class="ls-input" type="password" name="current" autocomplete="current-password" required></label>
        <label>New password (10 characters or more)<input class="ls-input" type="password" name="new" autocomplete="new-password" minlength="10" required></label>
        <div><button class="ls-btn ls-btn-primary" type="submit">Change password</button></div>
      </form>
    </section>
  </div>
</main>
<?php ls_render('foot'); ?>
