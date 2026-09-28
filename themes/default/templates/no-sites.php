<?php /* Logged in, no sites yet. Vars: $user, $csrf. */ ls_render('head', ['title' => 'LibreStats']); ?>
<main class="ls-auth"><div class="ls-card">
  <span class="ls-logo"><?php ls_render('logo'); ?>LibreStats</span>
  <h1>Add your first website</h1>
  <p>On your server, in the LibreStats folder, run:</p>
  <div class="ls-snippet"><pre><code>php bin/site.php add example.com America/Chicago</code></pre></div>
  <p class="ls-steps">Use your own domain and <a href="https://www.php.net/manual/en/timezones.php" rel="noopener">time zone</a>, then reload this page.</p>
  <form method="post" class="ls-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ls-btn" type="submit">Log out <?= h($user['email']) ?></button></form>
</div></main>
<?php ls_render('foot'); ?>
