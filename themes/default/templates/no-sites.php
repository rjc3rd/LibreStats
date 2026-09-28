<?php /* Logged in, no websites yet. Vars: $user, $csrf, $error. */ ls_render('head', ['title' => 'Add a website · LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?>LibreStats</span>
  <h1>Add your first website</h1>
  <p>Which website should LibreStats count? You’ll get its one-line tracking code next.</p>
  <form class="ls-form" method="post">
    <?php if ($error): ?><p class="ls-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <input type="hidden" name="action" value="site_add"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Domain<input class="ls-input" name="domain" placeholder="example.com" required value="<?= h($_POST['domain'] ?? '') ?>"></label>
    <label>Time zone (days are counted in it)
      <select class="ls-select ls-input" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= $tz === ($_POST['timezone'] ?? 'UTC') ? ' selected' : '' ?>><?= h($tz) ?></option><?php endforeach; ?></select>
    </label>
    <button class="ls-btn ls-btn-primary" type="submit">Add website</button>
  </form>
  <form method="post" class="ls-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ls-btn" type="submit">Log out <?= h($user['email']) ?></button></form>
</div></main>
<?php ls_render('foot'); ?>
