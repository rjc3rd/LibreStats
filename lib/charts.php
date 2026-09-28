<?php
// Charts drawn on the server as SVG: no chart library, no inline styles (colors come from the
// theme's CSS classes), and a text alternative for each. The theme's dashboard.js adds hover.

declare(strict_types=1);

// Line + area chart of one series. $rows: [key, label, value, extra]. Returns SVG + tooltip holder.
function ls_svg_line(array $rows, string $name, string $extraName = ''): string
{
    $w = 900; $h = 260; $pl = 46; $pr = 14; $pt = 16; $pb = 32;
    $n = count($rows);
    $max = max(1, ...array_map(fn ($r) => $r[2], $rows ?: [[0, '', 0]]));
    $step = ls_nice_step($max / 4);
    $top = $step * max(1, (int) ceil($max / $step));
    $x = fn (int $i) => $pl + ($n > 1 ? $i * ($w - $pl - $pr) / ($n - 1) : ($w - $pl - $pr) / 2);
    $y = fn (float $v) => $pt + (1 - $v / $top) * ($h - $pt - $pb);
    $grid = '';
    for ($g = 0; $g <= $top; $g += $step) {
        $gy = round($y($g), 1);
        $grid .= "<line class=\"ls-gridline\" x1=\"$pl\" x2=\"" . ($w - $pr) . "\" y1=\"$gy\" y2=\"$gy\"/>"
            . "<text class=\"ls-axis\" x=\"" . ($pl - 8) . "\" y=\"" . ($gy + 4) . "\" text-anchor=\"end\">" . h(ls_number($g)) . '</text>';
    }
    $labels = '';
    $every = max(1, (int) ceil($n / 6));
    foreach ($rows as $i => $r) {
        if ($i % $every === 0 || $i === $n - 1) {
            if ($i !== $n - 1 && $n - 1 - $i < $every / 2) {
                continue;  // keep the last label from colliding with the one before it
            }
            $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
            $labels .= '<text class="ls-axis" x="' . round($x($i), 1) . '" y="' . ($h - 10) . "\" text-anchor=\"$anchor\">" . h($r[1]) . '</text>';
        }
    }
    $pts = [];
    $path = '';
    foreach ($rows as $i => $r) {
        $px = round($x($i), 1);
        $py = round($y($r[2]), 1);
        $path .= ($i ? ' L' : 'M') . "$px,$py";
        $pts[] = [$px, $py, $r[1], $r[2], $r[3] ?? null];
    }
    $area = $n ? $path . ' L' . round($x($n - 1), 1) . ',' . ($h - $pb) . ' L' . round($x(0), 1) . ',' . ($h - $pb) . ' Z' : '';
    $dots = $n <= 31 ? implode('', array_map(fn ($p) => "<circle class=\"ls-point\" cx=\"{$p[0]}\" cy=\"{$p[1]}\" r=\"3\"/>", $pts)) : '';
    $summary = $n ? "$name from {$rows[0][1]} to {$rows[$n - 1][1]}: highest " . max(array_column($rows, 2)) . ', total ' . array_sum(array_column($rows, 2)) : "No $name yet";
    $data = h(json_encode(['w' => $w, 'name' => $name, 'extra' => $extraName, 'points' => $pts]));
    return "<div class=\"ls-chart\" data-chart=\"$data\"><svg viewBox=\"0 0 $w $h\" role=\"img\" aria-label=\"" . h($summary) . '">'
        . '<defs><linearGradient id="ls-fill" x1="0" x2="0" y1="0" y2="1"><stop class="ls-fill-top" offset="0"/><stop class="ls-fill-bottom" offset="1"/></linearGradient></defs>'
        . $grid . $labels . "<path class=\"ls-area\" d=\"$area\"/><path class=\"ls-line\" d=\"$path\"/>$dots"
        . "<line class=\"ls-cross\" y1=\"$pt\" y2=\"" . ($h - $pb) . '" x1="0" x2="0" visibility="hidden"/><circle class="ls-hover" r="5" visibility="hidden"/>'
        . "<rect class=\"ls-hit\" x=\"$pl\" y=\"$pt\" width=\"" . ($w - $pl - $pr) . '" height="' . ($h - $pt - $pb) . '"/></svg><div class="ls-tip" hidden></div></div>';
}

// 1, 2, 5, 10, 20, 50… step at or above $raw.
function ls_nice_step(float $raw): int
{
    $raw = max(1, $raw);
    $mag = 10 ** floor(log10($raw));
    foreach ([1, 2, 5, 10] as $m) {
        if ($m * $mag >= $raw) {
            return (int) ($m * $mag);
        }
    }
    return (int) (10 * $mag);
}

// Donut for a small part-of-whole (three to four parts at most, e.g. devices). $rows: [label, value].
// Colors are the theme's .ls-slice-1… classes; the legend beside it carries the labels and numbers.
function ls_svg_donut(array $rows, string $name): string
{
    $total = array_sum(array_column($rows, 1));
    $r = 42; $c = 2 * M_PI * $r; $offset = 0;
    $gap = count(array_filter($rows, fn ($x) => $x[1] > 0)) > 1 ? 2 : 0;
    $slices = '';
    foreach (array_slice($rows, 0, 4) as $i => [$label, $value]) {
        if ($value <= 0 || !$total) {
            continue;
        }
        $len = max(0, $value / $total * $c - $gap);
        $slices .= '<circle class="ls-slice ls-slice-' . ($i + 1) . "\" r=\"$r\" cx=\"60\" cy=\"60\" stroke-dasharray=\"" . round($len, 2) . ' ' . round($c, 2)
            . '" stroke-dashoffset="' . round(-$offset, 2) . '"><title>' . h($label . ': ' . round($value / $total * 100) . '%') . '</title></circle>';
        $offset += $value / $total * $c;
    }
    $alt = $total ? implode(', ', array_map(fn ($x) => $x[0] . ' ' . round($x[1] / $total * 100) . '%', $rows)) : 'No data yet';
    return "<svg class=\"ls-donut\" viewBox=\"0 0 120 120\" role=\"img\" aria-label=\"" . h("$name: $alt") . '">'
        . "<circle class=\"ls-donut-track\" r=\"$r\" cx=\"60\" cy=\"60\"/>$slices</svg>";
}
