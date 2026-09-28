<?php /* Top bar: logo, site picker, date range, live count, log out. Uses the page's variables. */ ?>
<header class="ls-bar"><div class="ls-bar-inner">
  <a class="ls-logo" href="./"><?php ls_render('logo'); ?>LibreStats</a>
  <form class="ls-controls" method="get" data-autosubmit>
    <label class="ls-sr" for="ls-site">Website</label>
    <select class="ls-select" id="ls-site" name="site">
      <?php foreach ($sites as $s): ?><option value="<?= h($s['domain']) ?>"<?= $s['id'] === $site['id'] ? ' selected' : '' ?>><?= h($s['domain']) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="range" value="<?= h($range === 'custom' ? '30d' : $range) ?>"><input type="hidden" name="view" value="<?= h($view ?? 'overview') ?>">
    <noscript><button class="ls-btn" type="submit">Show</button></noscript>
  </form>
  <nav class="ls-pills" aria-label="Date range">
    <?php foreach (LS_RANGES as $key => $name): ?><a href="<?= h(ls_link($site, $range, $from, $to, ['range' => $key, 'view' => ($view ?? 'overview') === 'overview' ? null : $view])) ?>"<?= $range === $key ? ' aria-current="page"' : '' ?>><?= h($name) ?></a><?php endforeach; ?>
    <a href="#ls-custom" data-toggle="ls-custom"<?= $range === 'custom' ? ' aria-current="page"' : '' ?>>Custom</a>
  </nav>
  <form class="ls-custom<?= $range === 'custom' ? ' is-open' : '' ?>" id="ls-custom" method="get">
    <input type="hidden" name="site" value="<?= h($site['domain']) ?>"><input type="hidden" name="range" value="custom"><input type="hidden" name="view" value="<?= h($view ?? 'overview') ?>">
    <label class="ls-sr" for="ls-from">From</label><input class="ls-input" type="date" id="ls-from" name="from" value="<?= h($from) ?>">
    <label class="ls-sr" for="ls-to">To</label><input class="ls-input" type="date" id="ls-to" name="to" value="<?= h($to) ?>">
    <button class="ls-btn" type="submit">Show</button>
  </form>
  <span class="ls-bar-grow"></span>
  <span class="ls-live<?= $live['visitors'] ? ' is-live' : '' ?>" data-live="<?= h('api.php?live&site=' . rawurlencode($site['domain'])) ?>"><span><b data-live-count><?= $live['visitors'] ?></b> <span data-live-word><?= $live['visitors'] === 1 ? 'person' : 'people' ?></span> on the site now</span></span>
  <form class="ls-user" method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ls-btn" type="submit" title="Logged in as <?= h($user['email']) ?>">Log out</button></form>
</div></header>
