<?php
/* The dashboard. Vars (see public/index.php): $user, $csrf, $sites, $site, $range, $label, $from, $to,
   $summary, $previous, $hasData, $series, $pages, $entries, $sources, $referrers, $campaigns, $countries,
   $devices, $browsers, $systems, $events, $funnel, $live, $scriptUrl. */

$q = fn (array $p) => '?' . http_build_query(array_merge(['site' => $site['domain'], 'range' => $range], $range === 'custom' ? ['from' => $from, 'to' => $to] : [], $p));

// One list card: rows [value, label, visitors, hits]; bars are relative to the top row.
$list = function (array $rows, string $empty, string $unit = 'visitors', bool $showValue = false) {
    if (!$rows) {
        echo '<p class="ls-empty">' . h($empty) . '</p>';
        return;
    }
    $max = max(1, $rows[0][2]);
    echo '<ul class="ls-list">';
    foreach ($rows as [$value, $label, $visitors, $hits]) {
        $sub = $showValue && $value !== $label ? '<small>' . h($value) . '</small>' : '';
        echo '<li><div class="ls-row"><span title="' . h($value) . '">' . h($label) . $sub . '</span><span class="ls-num">' . h(ls_number($visitors))
            . ($unit === 'hits' ? ' <small>/ ' . h(ls_number($hits)) . '</small>' : '') . '</span></div>'
            . '<div class="ls-progress"><span data-pct="' . round($visitors / $max * 100, 1) . '"></span></div></li>';
    }
    echo '</ul>';
};
$change = function (int|float $now, int|float $before) {
    $pct = ls_change($now, $before);
    if ($pct === null) {
        return '<span class="ls-change">new</span>';
    }
    $arrow = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '→');
    return '<span class="ls-change" title="Compared with the same length of time just before">' . $arrow . ' ' . abs($pct) . '%</span>';
};

ls_render('head', ['title' => $site['name'] . ' · LibreStats']);
?>
<header class="ls-bar"><div class="ls-bar-inner">
  <a class="ls-logo" href="./"><?php ls_render('logo'); ?>LibreStats</a>
  <form class="ls-controls" method="get" data-autosubmit>
    <label class="ls-sr" for="ls-site">Website</label>
    <select class="ls-select" id="ls-site" name="site">
      <?php foreach ($sites as $s): ?><option value="<?= h($s['domain']) ?>"<?= $s['id'] === $site['id'] ? ' selected' : '' ?>><?= h($s['domain']) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="range" value="<?= h($range === 'custom' ? '30d' : $range) ?>">
    <noscript><button class="ls-btn" type="submit">Show</button></noscript>
  </form>
  <nav class="ls-pills" aria-label="Date range">
    <?php foreach (LS_RANGES as $key => $name): ?><a href="<?= h($q(['range' => $key, 'from' => null, 'to' => null])) ?>"<?= $range === $key ? ' aria-current="page"' : '' ?>><?= h($name) ?></a><?php endforeach; ?>
    <a href="#ls-custom" data-toggle="ls-custom"<?= $range === 'custom' ? ' aria-current="page"' : '' ?>>Custom</a>
  </nav>
  <form class="ls-custom<?= $range === 'custom' ? ' is-open' : '' ?>" id="ls-custom" method="get">
    <input type="hidden" name="site" value="<?= h($site['domain']) ?>"><input type="hidden" name="range" value="custom">
    <label class="ls-sr" for="ls-from">From</label><input class="ls-input" type="date" id="ls-from" name="from" value="<?= h($from) ?>">
    <label class="ls-sr" for="ls-to">To</label><input class="ls-input" type="date" id="ls-to" name="to" value="<?= h($to) ?>">
    <button class="ls-btn" type="submit">Show</button>
  </form>
  <span class="ls-bar-grow"></span>
  <span class="ls-live<?= $live['visitors'] ? ' is-live' : '' ?>" data-live="<?= h('api.php?live&site=' . rawurlencode($site['domain'])) ?>"><span><b data-live-count><?= $live['visitors'] ?></b> <span data-live-word><?= $live['visitors'] === 1 ? 'person' : 'people' ?></span> on the site now</span></span>
  <form class="ls-user" method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ls-btn" type="submit" title="Logged in as <?= h($user['email']) ?>">Log out</button></form>
</div></header>

<main class="ls-main">
  <div class="ls-heading"><h1><?= h($site['domain']) ?></h1><p><?= h($label) ?> · <?= h(date('M j, Y', strtotime($from))) ?><?= $from !== $to ? ' – ' . h(date('M j, Y', strtotime($to))) : '' ?></p></div>

<?php if (!$hasData): ?>
  <section class="ls-card">
    <h2>Start counting <?= h($site['domain']) ?></h2>
    <p>Add this one line inside the <code>&lt;head&gt;</code> of every page (plain HTML, PHP, WordPress, anything):</p>
    <div class="ls-snippet"><pre><code id="ls-snippet">&lt;script src="<?= h($scriptUrl) ?>" data-site="<?= h($site['domain']) ?>" defer&gt;&lt;/script&gt;</code></pre><button class="ls-btn" type="button" data-copy="ls-snippet">Copy</button></div>
    <ol class="ls-steps">
      <li><b>HTML or PHP site:</b> paste it just before <code>&lt;/head&gt;</code> in your page template.</li>
      <li><b>WordPress:</b> paste it in your theme’s header, or with any “insert headers” plugin.</li>
      <li>Open your website in a browser. The first visit shows up here within seconds.</li>
    </ol>
    <p class="ls-waiting ls-live" data-live="<?= h('api.php?live&site=' . rawurlencode($site['domain'])) ?>" data-reload-on-visit>Waiting for the first visit…</p>
  </section>
<?php else: ?>
  <dl class="ls-tiles">
    <div class="ls-card ls-tile"><dt>Visitors</dt><dd><?= h(ls_number($summary['visitors'])) ?></dd><?= $change($summary['visitors'], $previous['visitors']) ?></div>
    <div class="ls-card ls-tile"><dt>Page views</dt><dd><?= h(ls_number($summary['pageviews'])) ?></dd><?= $change($summary['pageviews'], $previous['pageviews']) ?></div>
    <div class="ls-card ls-tile"><dt>Time per visit</dt><dd><?= h(ls_duration($summary['avg_duration'])) ?></dd><?= $change($summary['avg_duration'], $previous['avg_duration']) ?></div>
    <div class="ls-card ls-tile"><dt>Left after one page</dt><dd><?= h($summary['bounce_rate']) ?>%</dd><?= $change($summary['bounce_rate'], $previous['bounce_rate']) ?></div>
  </dl>

  <section class="ls-card ls-grid">
    <h2>Visitors <small><?= count($series) > 0 && strlen($series[0][0]) === 7 ? 'per month' : 'per day' ?></small></h2>
    <?= ls_svg_line(array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3]], $series), 'visitors', 'page views') ?>
    <details class="ls-table"><summary>Show as a table</summary><table><thead><tr><th>When</th><th>Visitors</th><th>Page views</th></tr></thead><tbody>
      <?php foreach ($series as $r): ?><tr><td><?= h($r[1]) ?></td><td><?= h($r[2]) ?></td><td><?= h($r[3]) ?></td></tr><?php endforeach; ?>
    </tbody></table></details>
  </section>

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

  <div class="ls-grid ls-grid-2">
    <section class="ls-card"><h2>Top pages <small>visitors / views</small></h2><?php $list($pages, 'No page views yet.', 'hits'); ?></section>
    <section class="ls-card"><h2>Where visitors came from <small>visitors</small></h2><?php $list($sources, 'No visits yet.'); ?></section>
  </div>
  <div class="ls-grid ls-grid-3">
    <section class="ls-card"><h2>Referring sites</h2><?php $list($referrers, 'Nobody has arrived from another site yet.', 'visitors', true); ?></section>
    <section class="ls-card"><h2>Landing pages</h2><?php $list($entries, 'No visits yet.'); ?></section>
    <section class="ls-card"><h2>Campaigns</h2><?php $list($campaigns, 'Tag links with ?utm_campaign=… to see them here.'); ?></section>
  </div>
  <div class="ls-grid ls-grid-3">
    <section class="ls-card"><h2>Countries</h2><?php $list($countries, 'No countries yet.'); ?></section>
    <section class="ls-card"><h2>Devices</h2>
      <?php if ($devices): $total = max(1, array_sum(array_column($devices, 2))); ?>
      <div class="ls-donut-wrap"><?= ls_svg_donut(array_map(fn ($d) => [$d[1], $d[2]], $devices), 'Devices') ?>
        <ul class="ls-legend"><?php foreach ($devices as $d): ?><li><i></i><span><?= h($d[1]) ?></span><b><?= round($d[2] / $total * 100) ?>%</b></li><?php endforeach; ?></ul>
      </div>
      <?php else: ?><p class="ls-empty">No visits yet.</p><?php endif; ?>
    </section>
    <section class="ls-card"><h2>Browsers</h2><?php $list($browsers, 'No visits yet.'); ?></section>
  </div>
  <div class="ls-grid ls-grid-2">
    <section class="ls-card"><h2>Operating systems</h2><?php $list($systems, 'No visits yet.'); ?></section>
    <section class="ls-card"><h2>Events <small>visitors / times</small></h2><?php $list($events, 'Outbound links, downloads and your own librestats("…") events show up here.', 'hits'); ?></section>
  </div>
<?php endif; ?>
</main>
<footer class="ls-foot">LibreStats counts visits without cookies and without storing IP addresses. Visitors are counted once per day; nobody can be followed from one day to the next.</footer>
<?php ls_render('foot'); ?>
