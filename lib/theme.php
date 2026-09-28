<?php
// Themes live in themes/<name>/: templates/*.php for the pages and assets/* for CSS, JS and
// images. A theme only needs the files it changes; anything missing comes from themes/default.
// Choose one with 'theme' => 'name' in config.php. Themes kept elsewhere (say, a private folder
// with your own branding) are found through 'theme_paths' => ['/path/to/themes'] in config.php.
// A theme's assets/custom.css is loaded after the main stylesheet, so a theme can just change the
// color and font tokens instead of copying everything.

declare(strict_types=1);

function ls_theme_dirs(): array
{
    $dirs = [];
    foreach ((array) (ls_config()['theme_paths'] ?? []) as $path) {
        $dirs[] = rtrim((string) $path, '/');
    }
    $dirs[] = LS_ROOT . '/themes';
    return $dirs;
}

function ls_theme(): string
{
    $name = (string) (ls_config()['theme'] ?? 'default');
    if (!preg_match('~^[a-z0-9-]+$~', $name)) {
        return 'default';
    }
    foreach (ls_theme_dirs() as $dir) {
        if (is_dir("$dir/$name")) {
            return $name;
        }
    }
    return 'default';
}

// The active theme's copy of a file, or the default theme's. $onlyActive skips the fallback.
function ls_theme_file(string $kind, string $file, bool $onlyActive = false): ?string
{
    foreach ($onlyActive ? [ls_theme()] : array_unique([ls_theme(), 'default']) as $theme) {
        foreach (ls_theme_dirs() as $dir) {
            $path = "$dir/$theme/$kind/$file";
            if (is_file($path)) {
                return $path;
            }
        }
    }
    return null;
}

// Renders a template with $vars as local variables.
function ls_render(string $template, array $vars = []): void
{
    $file = ls_theme_file('templates', "$template.php");
    if ($file === null) {
        throw new RuntimeException("LibreStats: no template $template");
    }
    extract($vars, EXTR_SKIP);
    include $file;
}

// Address of a theme asset, with a version so browsers pick up changes.
function ls_asset(string $file): string
{
    $path = ls_theme_file('assets', $file);
    return 'asset.php?f=' . rawurlencode($file) . ($path ? '&v=' . filemtime($path) : '');
}

function h(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ls_number(int|float $n): string
{
    return $n >= 10000 ? number_format($n / 1000, $n >= 100000 ? 0 : 1) . 'k' : number_format($n);
}

function ls_duration(int $seconds): string
{
    return $seconds < 60 ? "{$seconds}s" : intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
}

// Dashboard address keeping the site, range and custom dates; $change overrides (null removes).
function ls_link(array $site, string $range, string $from, string $to, array $change = []): string
{
    $q = ['site' => $site['domain'], 'range' => $range] + ($range === 'custom' ? ['from' => $from, 'to' => $to] : []);
    foreach ($change as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    if (($q['range'] ?? '') !== 'custom') {
        unset($q['from'], $q['to']);
    }
    return '?' . http_build_query($q);
}
