<?php
/* Settings: this website, its goals, all websites, logins. Vars: the common page variables, $goals,
   $timezones, $users. */
$action = h(ls_link($site, $range, $from, $to, ['view' => 'settings']));
$hidden = fn (string $name) => '<input type="hidden" name="action" value="' . h($name) . '"><input type="hidden" name="csrf" value="' . h($csrf) . '">';
$tzSelect = function (string $name, string $selected, string $id) use ($timezones) {
    echo '<select class="ls-select" name="' . h($name) . '" id="' . h($id) . '">';
    foreach ($timezones as $tz) {
        echo '<option' . ($tz === $selected ? ' selected' : '') . '>' . h($tz) . '</option>';
    }
    echo '</select>';
};
ls_render('head', ['title' => 'Settings · ' . $site['name'] . ' · LibreStats']);
ls_render('bar', get_defined_vars());
?>
<main class="ls-main">
  <?php ls_render('tabs', get_defined_vars()); ?>

  <div class="ls-grid ls-grid-2">
    <section class="ls-card">
      <h2>This website</h2>
      <form class="ls-form ls-form-tight" method="post" action="<?= $action ?>">
        <?= $hidden('site_update') ?>
        <label>Name<input class="ls-input" name="name" value="<?= h($site['name']) ?>" maxlength="100" required></label>
        <label for="ls-tz">Time zone <span class="ls-hint">Days and months are counted in this time zone.</span></label>
        <?php $tzSelect('timezone', $site['timezone'], 'ls-tz'); ?>
        <div><button class="ls-btn ls-btn-primary" type="submit">Save</button></div>
      </form>
    </section>
    <section class="ls-card">
      <h2>Tracking code</h2>
      <p class="ls-hint">Put this one line inside the <code>&lt;head&gt;</code> of every page of <?= h($site['domain']) ?>.</p>
      <div class="ls-snippet"><pre><code id="ls-snippet">&lt;script src="<?= h($scriptUrl) ?>" data-site="<?= h($site['domain']) ?>" defer&gt;&lt;/script&gt;</code></pre><button class="ls-btn" type="button" data-copy="ls-snippet">Copy</button></div>
      <p class="ls-hint ls-hint-block">Count your own moments from the page with <code>librestats("Signed up")</code>, or add <code>data-ls-event="Signed up"</code> to a button or link. Outbound links and file downloads are counted automatically.</p>
    </section>
  </div>

  <section class="ls-card ls-grid" id="goals">
    <h2>Goals <small>shown as a funnel, in this order</small></h2>
    <?php if ($goals): ?>
    <ol class="ls-goals">
      <?php foreach ($goals as $i => $g): ?>
      <li>
        <span class="ls-goal-name"><b><?= h($g['name']) ?></b><small><?= $g['kind'] === 'event' ? 'Event' : 'Page' ?>: <?= h($g['target']) ?></small></span>
        <form method="post" action="<?= $action ?>#goals"><?= $hidden('goal_up') ?><input type="hidden" name="goal" value="<?= (int) $g['id'] ?>"><button class="ls-btn ls-btn-icon" type="submit"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Move <?= h($g['name']) ?> earlier">↑</button></form>
        <form method="post" action="<?= $action ?>#goals"><?= $hidden('goal_down') ?><input type="hidden" name="goal" value="<?= (int) $g['id'] ?>"><button class="ls-btn ls-btn-icon" type="submit"<?= $i === count($goals) - 1 ? ' disabled' : '' ?> aria-label="Move <?= h($g['name']) ?> later">↓</button></form>
        <form method="post" action="<?= $action ?>#goals"><?= $hidden('goal_remove') ?><input type="hidden" name="goal" value="<?= (int) $g['id'] ?>"><button class="ls-btn" type="submit">Remove</button></form>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php else: ?><p class="ls-empty">No goals yet.</p><?php endif; ?>
    <form class="ls-form ls-form-row" method="post" action="<?= $action ?>#goals">
      <?= $hidden('goal_add') ?>
      <label>Goal name<input class="ls-input" name="name" maxlength="100" placeholder="Viewed pricing" required></label>
      <label>Reached when a visitor…<select class="ls-select" name="kind"><option value="path">opens a page</option><option value="event">triggers an event</option></select></label>
      <label>Page or event<input class="ls-input" name="target" maxlength="512" placeholder="/pricing" required></label>
      <div><button class="ls-btn ls-btn-primary" type="submit">Add goal</button></div>
    </form>
    <p class="ls-hint ls-hint-block">A page goal can end with <code>*</code> to cover every page that starts the same way, like <code>/blog/*</code>.</p>
  </section>

  <div class="ls-grid ls-grid-2">
    <section class="ls-card">
      <h2>Websites</h2>
      <ul class="ls-list ls-sites">
        <?php foreach ($sites as $s): ?><li><a href="<?= h(ls_link($s, $range, $from, $to, ['view' => 'settings'])) ?>"<?= $s['id'] === $site['id'] ? ' aria-current="page"' : '' ?>><?= h($s['domain']) ?></a> <small><?= h($s['timezone']) ?></small></li><?php endforeach; ?>
      </ul>
      <form class="ls-form ls-form-tight" method="post" action="<?= $action ?>">
        <?= $hidden('site_add') ?>
        <label>Add a website<input class="ls-input" name="domain" placeholder="example.com" required></label>
        <label for="ls-tz-new">Its time zone</label>
        <?php $tzSelect('timezone', $site['timezone'], 'ls-tz-new'); ?>
        <div><button class="ls-btn ls-btn-primary" type="submit">Add website</button></div>
      </form>
    </section>
    <section class="ls-card">
      <h2>Your login</h2>
      <form class="ls-form ls-form-tight" method="post" action="<?= $action ?>">
        <?= $hidden('password') ?>
        <label>Current password<input class="ls-input" type="password" name="current" autocomplete="current-password" required></label>
        <label>New password (10 characters or more)<input class="ls-input" type="password" name="new" autocomplete="new-password" minlength="10" required></label>
        <div><button class="ls-btn ls-btn-primary" type="submit">Change password</button></div>
      </form>
      <h2 class="ls-subhead">People who can log in</h2>
      <ul class="ls-list ls-sites"><?php foreach ($users as $u): ?><li><?= h($u['email']) ?><?= $u['email'] === $user['email'] ? ' <small>you</small>' : '' ?></li><?php endforeach; ?></ul>
      <form class="ls-form ls-form-tight" method="post" action="<?= $action ?>">
        <?= $hidden('user_add') ?>
        <label>Their email<input class="ls-input" type="email" name="email" autocomplete="off" required></label>
        <label>A password for them (10 characters or more)<input class="ls-input" type="password" name="password" autocomplete="new-password" minlength="10" required></label>
        <div><button class="ls-btn" type="submit">Add login</button></div>
      </form>
    </section>
  </div>

  <section class="ls-card ls-grid ls-danger">
    <h2>Delete <?= h($site['domain']) ?></h2>
    <p class="ls-hint">This deletes the website from LibreStats with every number it has, including the monthly totals. It can’t be undone. Remove the tracking code from its pages too.</p>
    <form class="ls-form ls-form-row" method="post" action="<?= $action ?>">
      <?= $hidden('site_remove') ?>
      <label>Type <b><?= h($site['domain']) ?></b> to confirm<input class="ls-input" name="confirm" autocomplete="off" required></label>
      <div><button class="ls-btn ls-btn-danger" type="submit">Delete website</button></div>
    </form>
  </section>
</main>
<?php ls_render('foot'); ?>
