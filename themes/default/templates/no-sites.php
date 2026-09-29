<?php /* Logged in, no websites yet. Vars: $user, $csrf, $error, $viewer (a login that can only look). */ ls_render('head', ['title' => ($viewer ?? false) ? 'LibreStats' : 'Add a website · LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?></span>
<?php if ($viewer ?? false): ?>
  <h1>Nothing to look at yet</h1>
  <p>No website has been shared with you yet. Once one is, it will show up here.</p>
<?php else: ?>
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
<?php endif; ?>
  <form method="post" class="ls-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ls-btn" type="submit">Log out <?= h($user['email']) ?></button></form>
</div></main>
<?php ls_render('foot'); ?>
