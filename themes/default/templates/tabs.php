<?php /* Site heading and the section tabs. Uses the page's variables. */ ?>
<div class="ls-heading"><h1><?= h($site['domain']) ?></h1><?php if (($view ?? 'overview') !== 'settings'): ?><p><?= h($label) ?> · <?= h(date('M j, Y', strtotime($from))) ?><?= $from !== $to ? ' – ' . h(date('M j, Y', strtotime($to))) : '' ?></p><?php endif; ?></div>
<nav class="ls-tabs" aria-label="Sections">
  <?php foreach ($views as $key => $name): ?><a href="<?= h(ls_link($site, $range, $from, $to, ['view' => $key === 'overview' ? null : $key])) ?>"<?= ($view ?? 'overview') === $key ? ' aria-current="page"' : '' ?>><?= h($name) ?></a><?php endforeach; ?>
</nav>
<?php if (!empty($flash)): ?><p class="ls-flash ls-flash-<?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></p><?php endif; ?>
