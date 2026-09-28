<?php /* Login. Vars: $error, $csrf. */ ls_render('head', ['title' => 'Log in · LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?>LibreStats</span>
  <h1>Log in</h1>
  <p>See how many people visit your websites.</p>
  <form class="ls-form" method="post">
    <?php if ($error): ?><p class="ls-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Username or email<input class="ls-input" type="text" name="email" autocapitalize="none" spellcheck="false" autocomplete="username" required value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>Password<input class="ls-input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="ls-btn ls-btn-primary" type="submit">Log in</button>
  </form>
</div></main>
<?php ls_render('foot'); ?>
