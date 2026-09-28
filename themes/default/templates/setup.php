<?php /* First run: create the owner's login. Vars: $error, $csrf. */ ls_render('head', ['title' => 'Set up LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?>LibreStats</span>
  <h1>Welcome! Create your login.</h1>
  <p>This is the first visit to your new LibreStats. Choose the email and password you’ll use to see your numbers. This page only appears once.</p>
  <form class="ls-form" method="post">
    <?php if ($error): ?><p class="ls-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <input type="hidden" name="action" value="setup"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Email<input class="ls-input" type="email" name="email" autocomplete="username" required value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>Password (10 characters or more)<input class="ls-input" type="password" name="password" autocomplete="new-password" minlength="10" required></label>
    <label>Type it again<input class="ls-input" type="password" name="password2" autocomplete="new-password" minlength="10" required></label>
    <button class="ls-btn ls-btn-primary" type="submit">Create my login</button>
  </form>
</div></main>
<?php ls_render('foot'); ?>
