<?php
defined('MANHUANI') || exit;

function page_url(string $page, array $params = []): string
{
    return '?' . http_build_query(['page' => $page] + $params);
}

function layout_start(string $title, string $active = ''): void
{
    $nav = ['dashboard' => 'Dashboard', 'sources' => 'Sources', 'updates' => 'Updates', 'series_info' => 'Series info', 'logs' => 'Logs'];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | Manhuani Admin</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css?v=<?= MANHUANI_VERSION ?>">
</head>
<body>
<header class="top">
  <div class="wrap top-inner">
    <a class="logo" href="<?= h(page_url('dashboard')) ?>"><span class="mark">漫</span><span>Manhua<b>ni</b></span> <small>Admin</small></a>
    <?php if (is_logged_in()): ?>
    <nav>
      <?php foreach ($nav as $k => $label): ?>
        <a href="<?= h(page_url($k)) ?>" class="<?= $active === $k ? 'active' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="../" target="_blank" rel="noopener">View site ↗</a>
      <form method="post" action="<?= h(page_url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach (flash() as [$type, $msg]): ?>
  <div class="alert <?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function layout_end(): void
{
    ?></main>
<footer class="wrap foot">Collects metadata and links from authorized feeds and official APIs only. No CAPTCHAs, logins, paywalls or images.</footer>
</body>
</html><?php
}

function badge(string $text, string $kind): string
{
    return '<span class="badge ' . h($kind) . '">' . h($text) . '</span>';
}

function source_status_badge(array $s): string
{
    if ($s['enabled']) {
        return (int) $s['fail_count'] > 0 ? badge('Enabled · failing', 'warn') : badge('Enabled', 'ok');
    }
    if ($s['robots_status'] === 'disallowed') return badge('Blocked by robots.txt', 'bad');
    if ($s['robots_status'] === 'blocked') return badge('Anti-bot / login wall', 'bad');
    if (!$s['feed_url']) return badge($s['check_summary'] ? 'No feed found' : 'Not tested', 'muted');
    return badge('Disabled', 'muted');
}
