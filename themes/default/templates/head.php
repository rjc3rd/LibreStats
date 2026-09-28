<?php /* Page start. Vars: $title. */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title ?? 'LibreStats') ?></title>
<link rel="stylesheet" href="<?= h(ls_asset('theme.css')) ?>">
<?php if (ls_theme_file('assets', 'custom.css', true)): ?><link rel="stylesheet" href="<?= h(ls_asset('custom.css')) ?>"><?php endif; ?>
<link rel="icon" href="<?= h(ls_asset('icon.svg')) ?>" type="image/svg+xml">
</head>
<body>
