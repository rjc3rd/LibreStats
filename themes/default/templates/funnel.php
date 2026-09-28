<?php /* Goals funnel. Uses $funnel (and the page's variables). */ ?>
<?php if ($funnel): ?>
  <section class="ls-card ls-grid">
    <h2>Goals <small>visits that reached each step</small></h2>
    <ol class="ls-funnel">
      <?php foreach ($funnel as [$name, $count, $pct]): ?>
      <li><b><?= h(ls_number($count)) ?></b><span><?= h($name) ?></span><small><?= $pct === null ? 'first step' : h($pct) . '% of the step before' ?></small></li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>
