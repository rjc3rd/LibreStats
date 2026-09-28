<?php
/* A section's full lists (Pages, Sources, Locations, Devices, Events & goals). Vars: the common page
   variables, $sections = [[title, list id, rows [value, label, visitors, count], count name]], $funnel. */
ls_render('head', ['title' => $views[$view] . ' · ' . $site['name'] . ' · LibreStats']);
ls_render('bar', get_defined_vars());
?>
<main class="ls-main">
  <?php ls_render('tabs', get_defined_vars()); ?>
  <?php if ($view === 'events'): ?>
    <?php if ($funnel): ls_render('funnel', get_defined_vars()); else: ?>
    <section class="ls-card ls-grid"><h2>Goals</h2><p class="ls-empty">No goals yet. Add the steps you care about (for example “Viewed pricing”, then “Signed up”) in <a href="<?= h(ls_link($site, $range, $from, $to, ['view' => 'settings'])) ?>#goals">Settings</a>, and they show here as a funnel.</p></section>
    <?php endif; ?>
  <?php endif; ?>
  <?php foreach ($sections as [$title, $id, $rows, $countName]): $total = max(1, array_sum(array_column($rows, 2))); ?>
  <section class="ls-card ls-grid">
    <h2><?= h($title) ?><?php if ($rows): ?> <a class="ls-csv" href="<?= h(ls_link($site, $range, $from, $to, ['view' => $view, 'export' => $id])) ?>">Download CSV</a><?php endif; ?></h2>
    <?php if (!$rows): ?><p class="ls-empty">Nothing here for this date range.</p><?php else: ?>
    <div class="ls-scroll"><table class="ls-data">
      <thead><tr><th scope="col">#</th><th scope="col"><?= h($title) ?></th><th scope="col">Visitors</th><th scope="col"><?= h(ucfirst($countName)) ?></th><th scope="col">Share of visitors</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => [$value, $name, $visitors, $count]): $pct = round($visitors / $total * 100, 1); ?>
        <tr><td><?= $i + 1 ?></td><td class="ls-data-name" title="<?= h($value) ?>"><?= h($name) ?><?php if ($value !== $name): ?> <small><?= h($value) ?></small><?php endif; ?></td>
          <td><?= h(number_format($visitors)) ?></td><td><?= h(number_format($count)) ?></td>
          <td class="ls-data-share"><span class="ls-progress"><span data-pct="<?= $pct ?>"></span></span><span><?= $pct ?>%</span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</main>
<footer class="ls-foot">LibreStats counts visits without cookies and without storing IP addresses. Visitors are counted once per day; nobody can be followed from one day to the next.</footer>
<?php ls_render('foot'); ?>
