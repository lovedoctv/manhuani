<?php
// Manhuani crawler dashboard. Private: password protected, not indexed.
declare(strict_types=1);

define('MANHUANI', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/views.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');

if ($missing = missing_requirements()) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Server check</title><body style="font-family:system-ui;padding:40px;max-width:640px;margin:auto">'
        . '<h1>This server is missing something</h1><ul><li>' . implode('</li><li>', array_map('h', $missing)) . '</li></ul>'
        . '<p>In cPanel, open <b>Select PHP Version</b> (or <b>MultiPHP Manager</b>) and choose PHP 8.1 or newer with these extensions turned on.</p></body>';
    exit;
}

if (private_dir() === null) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Not installed</title><body style="font-family:system-ui;padding:40px;max-width:640px;margin:auto">'
        . '<h1>Dashboard not installed yet</h1><p>Open cPanel → <b>Terminal</b> and run:</p>'
        . '<pre style="background:#eee;padding:12px">php ~/aduna.fit/manhuani/admin/cli.php setup</pre>'
        . '<p>Then reload this page. Full steps are in <code>docs/CRAWLER.md</code>.</p></body>';
    exit;
}

try {
    db();
    db_ensure_schema();
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[manhuani] database error: ' . $e->getMessage());
    echo 'Database connection failed. Check config.php in your manhuani_private folder.';
    exit;
}

// After deploying a new version, rewrite the website's data files at once so the site
// uses the new format without waiting for the next cron run.
if (meta_get('published_version') !== MANHUANI_VERSION) {
    try {
        publish_updates();
    } catch (Throwable $e) {
        log_error('Could not refresh the website after the update: ' . $e->getMessage());
    }
}

start_session();
$page = (string) ($_GET['page'] ?? 'dashboard');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function redirect(string $page, array $params = []): void
{
    header('Location: ' . page_url($page, $params), true, 303);
    exit;
}

// ---------- Login ----------
if (!is_logged_in()) {
    $error = null;
    if ($isPost && $page === 'login') {
        if (!csrf_ok()) {
            $error = 'Your session expired. Please try again.';
        } elseif (login_locked()) {
            $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MIN . ' minutes and try again.';
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect('dashboard');
        } else {
            $error = 'Wrong password.';
        }
    }
    http_response_code($error ? 401 : 200);
    layout_start('Log in');
    ?>
    <section class="card login">
      <h1>Log in</h1>
      <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
      <form method="post" action="<?= h(page_url('login')) ?>">
        <?= csrf_field() ?>
        <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
        <button class="btn">Log in</button>
      </form>
    </section>
    <?php
    layout_end();
    exit;
}

// ---------- Actions (POST only, CSRF protected) ----------
if ($isPost) {
    if (!csrf_ok()) {
        if ($page === 'pages_upload') {
            // PHP drops the whole form when an upload is bigger than post_max_size.
            $tooBig = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes((string) ini_get('post_max_size'));
            flash('error', $tooBig ? 'That upload was larger than your server allows in one go (' . ini_get('post_max_size') . '). Upload fewer pages at a time.' : 'Your session expired. Please try again.');
            redirect('chapter_pages', ['id' => (int) ($_GET['id'] ?? 0)]);
        }
        flash('error', 'Your session expired. Please try again.');
        redirect($page === 'logout' ? 'dashboard' : $page);
    }
    $id = (int) ($_POST['id'] ?? 0);
    switch ($page) {
        case 'logout':
            logout();
            redirect('login');
        case 'source_save':
            [$newId, $err] = source_save($id ?: null, $_POST);
            if ($err) {
                flash('error', $err);
                redirect('source_form', $id ? ['id' => $id] : []);
            }
            publish_updates(); // hosting permission may have changed
            flash('success', $id ? 'Source saved.' : 'Source added. Click "Test" to check whether it can be used.');
            redirect('sources');
        case 'source_test':
            @set_time_limit(90);
            $r = source_test($id);
            $_SESSION['test_result'] = ['id' => $id, 'lines' => $r['lines'], 'ok' => $r['ok']];
            redirect('sources');
        case 'source_enable':
        case 'source_disable':
            $err = source_set_enabled($id, $page === 'source_enable');
            flash($err ? 'error' : 'success', $err ?? ($page === 'source_enable' ? 'Source enabled. It will be checked on the next scheduled run.' : 'Source disabled.'));
            redirect('sources');
        case 'source_delete':
            source_delete($id, !empty($_POST['with_updates']));
            publish_updates();
            flash('success', 'Source removed.');
            redirect('sources');
        case 'source_run':
            @set_time_limit(90);
            $stats = crawler_run($id);
            flash($stats['errors'] ? 'error' : 'success', $stats['checked'] ? "Checked now: {$stats['new']} new update(s)" . ($stats['errors'] ? ' with errors (see Logs).' : '.') : 'Nothing was checked. Is the source enabled?');
            redirect('sources');
        case 'run_all':
            @set_time_limit(120);
            $stats = crawler_run(null, true);
            flash('success', "Checked {$stats['checked']} source(s): {$stats['new']} new update(s), {$stats['errors']} error(s).");
            redirect('dashboard');
        case 'update_hide':
        case 'update_show':
            db_run('UPDATE updates SET hidden = ? WHERE id = ?', [$page === 'update_hide' ? 1 : 0, $id]);
            publish_updates();
            flash('success', $page === 'update_hide' ? 'Update hidden from the website.' : 'Update shown on the website.');
            redirect('updates', array_filter(['q' => $_POST['q'] ?? '']));
        case 'update_delete':
            pages_clear($id);
            db_run('DELETE FROM updates WHERE id = ?', [$id]);
            publish_updates();
            flash('success', 'Update deleted.');
            redirect('updates', array_filter(['q' => $_POST['q'] ?? '']));
        case 'publish':
            $n = publish_updates();
            flash('success', "Website updated with $n update(s).");
            redirect('updates');
        case 'connector_enable':
        case 'connector_disable':
            $name = (string) ($_POST['name'] ?? '');
            $err = connector_set_enabled($name, $page === 'connector_enable', !empty($_POST['terms']));
            flash($err ? 'error' : 'success', $err ?? (connector_label($name) . ($page === 'connector_enable' ? ' enabled. It runs with the next scheduled check.' : ' disabled.')));
            redirect('connectors');
        case 'connector_test':
            @set_time_limit(90);
            $name = (string) ($_POST['name'] ?? '');
            $r = connector_test($name);
            $_SESSION['test_result'] = ['name' => $name, 'lines' => $r['lines'], 'ok' => $r['ok']];
            redirect('connectors');
        case 'connector_run':
            @set_time_limit(120);
            $name = (string) ($_POST['name'] ?? '');
            $st = connectors_run(true, $name);
            if ($st['new'] || $st['titles']) {
                publish_updates();
            }
            flash($st['errors'] ? 'error' : 'success', $st['checked'] ? connector_label($name) . ": {$st['titles']} new title(s), {$st['new']} new chapter(s)" . ($st['errors'] ? '. It failed, see Logs.' : '.') : 'Enable the connector first.');
            redirect('connectors');
        case 'title_hide':
        case 'title_show':
            db_run('UPDATE titles SET hidden = ? WHERE id = ?', [$page === 'title_hide' ? 1 : 0, $id]);
            publish_updates();
            flash('success', $page === 'title_hide' ? 'Title and its chapters hidden from the website.' : 'Title shown on the website.');
            redirect('titles', array_filter(['q' => $_POST['q'] ?? '']));
        case 'pages_upload':
            @set_time_limit(120);
            $u = chapter_for_pages($id);
            if (!$u) {
                flash('error', 'Chapter not found.');
                redirect('updates');
            }
            [$added, $problems] = pages_upload($u, $_FILES['pages'] ?? []);
            publish_updates();
            if ($added) {
                flash('success', "$added page(s) added.");
            }
            foreach ($problems ?: ($added ? [] : ['Choose one or more images first.']) as $msg) {
                flash('error', $msg);
            }
            redirect('chapter_pages', ['id' => $id]);
        case 'pages_clear':
            pages_clear($id);
            publish_updates();
            flash('success', 'All pages of this chapter were deleted.');
            redirect('chapter_pages', ['id' => $id]);
        case 'logs_clear':
            db_run('DELETE FROM logs');
            log_info('Logs cleared');
            redirect('logs');
    }
    http_response_code(400);
    exit('Unknown action');
}

// ---------- Pages ----------
switch ($page) {
    case 'sources':
        $sources = sources_all();
        $test = $_SESSION['test_result'] ?? null;
        unset($_SESSION['test_result']);
        layout_start('Sources', 'sources');
        ?>
        <div class="head"><h1>Sources</h1><a class="btn" href="<?= h(page_url('source_form')) ?>">+ Add source</a></div>
        <p class="muted">Add the websites you have permission to follow. <b>Test</b> checks robots.txt and looks for an official feed (RSS, Atom or JSON Feed). A source can only be enabled when it allows automated access, has a feed, and you've confirmed permission.</p>
        <p class="muted">To follow a series on <b>MangaDex</b>, paste its title page (for example <code>https://mangadex.org/title/…</code>). It uses MangaDex's official API, one series per source.</p>
        <?php if ($test): ?>
          <div class="card test <?= $test['ok'] ? 'pass' : 'fail' ?>">
            <h2>Test result: <?= $test['ok'] ? 'ready to enable' : 'can\'t be used automatically' ?></h2>
            <ul class="checks"><?php foreach ($test['lines'] as [$lvl, $txt]): ?><li class="<?= h($lvl) ?>"><?= h($txt) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
        <?php if (!$sources): ?>
          <div class="card empty">No sources yet. Click <b>+ Add source</b> and paste a website address.</div>
        <?php endif; ?>
        <div class="sources">
        <?php foreach ($sources as $s): ?>
          <article class="card source">
            <div class="source-head">
              <div>
                <h2><?= h($s['name']) ?></h2>
                <a class="url" href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h($s['url']) ?></a>
              </div>
              <?= source_status_badge($s) ?>
            </div>
            <dl class="facts">
              <div><dt>Method</dt><dd><?= $s['method'] ? h($s['method'] === 'mangadex' ? 'MangaDex API' : strtoupper($s['method']) . ' feed') : '—' ?></dd></div>
              <div><dt>Access</dt><dd><?= h(['allowed' => 'allowed', 'disallowed' => 'robots.txt says no', 'blocked' => 'anti-bot wall', 'api' => 'official API', 'unknown' => 'not tested'][$s['robots_status']] ?? $s['robots_status']) ?><?= $s['crawl_delay'] ? ' · delay ' . (int) $s['crawl_delay'] . 's' : '' ?></dd></div>
              <div><dt>Every</dt><dd><?= (int) $s['interval_minutes'] ?> min</dd></div>
              <div><dt>Updates</dt><dd><?= (int) $s['update_count'] ?></dd></div>
              <div><dt>Last check</dt><dd><?= h(ago($s['last_checked_at'])) ?></dd></div>
              <div><dt>Next check</dt><dd><?= $s['enabled'] ? h(ago($s['next_check_at'] ?? now())) : '—' ?></dd></div>
            </dl>
            <?php if ($s['feed_url']): ?><p class="small muted"><?= $s['method'] === 'mangadex' ? 'API' : 'Feed' ?>: <?= h($s['feed_url']) ?></p><?php endif; ?>
            <?php if (!empty($s['hosting_allowed'])): ?><p class="small">Hosting permission: yes<?= $s['hosting_note'] ? ' · ' . h($s['hosting_note']) : '' ?></p><?php endif; ?>
            <?php if (!$s['permission_confirmed']): ?><p class="small warn-text">Permission not confirmed yet. Edit the source to confirm.</p><?php endif; ?>
            <?php if ($s['last_error']): ?><p class="small error-text">Last error: <?= h($s['last_error']) ?></p><?php endif; ?>
            <div class="actions">
              <form method="post" action="<?= h(page_url('source_test')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn small">Test</button></form>
              <?php if ($s['enabled']): ?>
                <form method="post" action="<?= h(page_url('source_run')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn small ghost">Check now</button></form>
                <form method="post" action="<?= h(page_url('source_disable')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn small ghost">Disable</button></form>
              <?php else: ?>
                <form method="post" action="<?= h(page_url('source_enable')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn small ghost">Enable</button></form>
              <?php endif; ?>
              <a class="btn small ghost" href="<?= h(page_url('source_form', ['id' => $s['id']])) ?>">Edit</a>
              <details class="danger">
                <summary class="btn small ghost">Remove</summary>
                <form method="post" action="<?= h(page_url('source_delete')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <label class="check"><input type="checkbox" name="with_updates" value="1"> Also delete its <?= (int) $s['update_count'] ?> update(s)</label>
                  <button class="btn small bad">Yes, remove</button>
                </form>
              </details>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <?php
        layout_end();
        break;

    case 'source_form':
        $id = (int) ($_GET['id'] ?? 0);
        $s = $id ? source_get($id) : null;
        if ($id && !$s) {
            flash('error', 'Source not found.');
            redirect('sources');
        }
        layout_start($s ? 'Edit source' : 'Add source', 'sources');
        ?>
        <div class="head"><h1><?= $s ? 'Edit source' : 'Add source' ?></h1></div>
        <form class="card form" method="post" action="<?= h(page_url('source_save')) ?>">
          <?= csrf_field() ?>
          <?php if ($s): ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><?php endif; ?>
          <label>Website address (or its feed address)
            <input type="url" name="url" required maxlength="500" placeholder="https://example.com/, a feed, or https://mangadex.org/title/…" value="<?= h($s['url'] ?? '') ?>">
          </label>
          <label>Name <input type="text" name="name" maxlength="120" placeholder="Shown in the dashboard and on update cards" value="<?= h($s['name'] ?? '') ?>"></label>
          <label>Check every (minutes, at least <?= (int) config('min_interval_minutes') ?>)
            <input type="number" name="interval_minutes" min="<?= (int) config('min_interval_minutes') ?>" max="10080" value="<?= (int) ($s['interval_minutes'] ?? config('default_interval_minutes')) ?>">
          </label>
          <label>Notes (optional) <textarea name="notes" rows="3" maxlength="1000" placeholder="e.g. where the permission came from"><?= h($s['notes'] ?? '') ?></textarea></label>
          <label class="check">
            <input type="checkbox" name="permission_confirmed" value="1" <?= !empty($s['permission_confirmed']) ? 'checked' : '' ?>>
            I have checked this site's terms and I'm allowed to collect its chapter metadata (titles, chapter numbers, dates and links).
          </label>
          <fieldset class="hosting">
            <legend>Hosting permission (optional)</legend>
            <label class="check">
              <input type="checkbox" name="hosting_allowed" value="1" <?= !empty($s['hosting_allowed']) ? 'checked' : '' ?>>
              I have written permission from the publisher or rights holder to show this source's chapter pages on Aduna.fit.
            </label>
            <label>Where the permission came from <input type="text" name="hosting_note" maxlength="500" placeholder="e.g. Email from the publisher, 2026-10-20" value="<?= h($s['hosting_note'] ?? '') ?>"></label>
            <p class="small muted">With this on, you can upload pages for this source's chapters on the Updates page, and visitors read them on your site. The crawler never downloads pages itself. Without it, visitors get a "Visit original source" link. Untick it and its pages disappear from the website at once.</p>
          </fieldset>
          <p class="small muted">For MangaDex, ticking this box also means you accept <a href="https://api.mangadex.org/docs/" target="_blank" rel="noopener noreferrer">its API terms</a>: your site must not run ads or paid services, MangaDex and the scanlation group are credited on every update (this happens automatically), and you remove updates if a group asks.</p>
          <div class="actions"><button class="btn">Save</button><a class="btn ghost" href="<?= h(page_url('sources')) ?>">Cancel</a></div>
        </form>
        <?php
        layout_end();
        break;

    case 'updates':
        $q = trim((string) ($_GET['q'] ?? ''));
        $p = max(1, (int) ($_GET['p'] ?? 1));
        $per = 50;
        $where = $q !== '' ? 'WHERE u.series_title LIKE ?' : '';
        $params = $q !== '' ? ['%' . $q . '%'] : [];
        $total = (int) (db_one("SELECT COUNT(*) AS n FROM updates u $where", $params)['n'] ?? 0);
        $rows = db_all("SELECT u.*, s.name AS source_name, s.hosting_allowed FROM updates u LEFT JOIN sources s ON s.id = u.source_id $where ORDER BY COALESCE(u.release_date, u.created_at) DESC, u.id DESC LIMIT $per OFFSET " . (($p - 1) * $per), $params);
        layout_start('Updates', 'updates');
        ?>
        <div class="head">
          <h1>Updates <small class="muted">(<?= $total ?>)</small></h1>
          <form method="post" action="<?= h(page_url('publish')) ?>"><?= csrf_field() ?><button class="btn ghost">Refresh website now</button></form>
        </div>
        <form class="search" method="get"><input type="hidden" name="page" value="updates"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by series title"><button class="btn small">Search</button></form>
        <div class="card table-wrap">
          <table>
            <thead><tr><th>Series</th><th>Chapter</th><th>Released</th><th>Source</th><th>Credit</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6" class="muted">No updates yet. Enabled sources are checked by the cron job.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr class="<?= $r['hidden'] ? 'hidden-row' : '' ?>">
                <td><?= h($r['series_title']) ?></td>
                <td><a href="<?= h($r['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Ch. <?= h(format_chapter($r['chapter_number'])) ?></a><?= $r['chapter_label'] ? ' <span class="muted small">' . h($r['chapter_label']) . '</span>' : '' ?></td>
                <td class="nowrap"><?= h($r['release_date'] ? substr($r['release_date'], 0, 16) : '—') ?></td>
                <td><?= h($r['connector'] ? connector_label($r['connector']) : ($r['source_name'] ?? '(removed)')) ?></td>
                <td class="small"><?= h($r['credit'] ?? '') ?></td>
                <td class="nowrap">
                  <?php if (!empty($r['hosting_allowed']) || $r['pages'] !== null): ?><a class="link" href="<?= h(page_url('chapter_pages', ['id' => $r['id']])) ?>">Pages (<?= count(update_pages($r)) ?>)</a><?php endif; ?>
                  <form method="post" action="<?= h(page_url($r['hidden'] ? 'update_show' : 'update_hide')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><button class="link"><?= $r['hidden'] ? 'Show' : 'Hide' ?></button></form>
                  <form method="post" action="<?= h(page_url('update_delete')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><button class="link bad">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($total > $per): ?>
          <nav class="pager">
            <?php if ($p > 1): ?><a href="<?= h(page_url('updates', ['q' => $q, 'p' => $p - 1])) ?>">« Newer</a><?php endif; ?>
            <span>Page <?= $p ?> of <?= (int) ceil($total / $per) ?></span>
            <?php if ($p * $per < $total): ?><a href="<?= h(page_url('updates', ['q' => $q, 'p' => $p + 1])) ?>">Older »</a><?php endif; ?>
          </nav>
        <?php endif;
        layout_end();
        break;

    case 'page_image':
        // Shows an uploaded page to you on the dashboard (the originals are private).
        $u = chapter_for_pages((int) ($_GET['id'] ?? 0));
        $name = (string) ($_GET['f'] ?? '');
        $file = $u && in_array($name, update_pages($u), true) ? pages_private_dir((int) $u['id']) . "/$name" : null;
        if ($file === null || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . PAGE_MIME[pathinfo($name, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'chapter_pages':
        $u = chapter_for_pages((int) ($_GET['id'] ?? 0));
        if (!$u) {
            flash('error', 'Chapter not found.');
            redirect('updates');
        }
        $pages = update_pages($u);
        $hiddenTitle = $u['title_id'] && ($t = title_get((int) $u['title_id'])) && $t['hidden'];
        $shown = $pages && !empty($u['hosting_allowed']) && !$u['hidden'] && !$hiddenTitle;
        layout_start('Chapter pages', 'updates');
        ?>
        <div class="head"><h1><?= h($u['series_title']) ?> · Chapter <?= h(format_chapter((float) $u['chapter_number'])) ?></h1><a class="btn ghost" href="<?= h(page_url('updates', ['q' => $u['series_title']])) ?>">Back to updates</a></div>
        <div class="card">
          <dl class="facts">
            <div><dt>Source</dt><dd><?= h($u['source_name'] ?? '(removed)') ?></dd></div>
            <div><dt>Hosting permission</dt><dd><?= !empty($u['hosting_allowed']) ? 'Yes' : 'No' ?></dd></div>
            <div><dt>Pages</dt><dd><?= count($pages) ?></dd></div>
            <div><dt>On the website</dt><dd><?= $shown ? 'Shown' : 'Not shown' ?></dd></div>
          </dl>
          <?php if (!empty($u['hosting_note'])): ?><p class="small muted">Permission: <?= h($u['hosting_note']) ?></p><?php endif; ?>
          <p class="small"><a href="<?= h($u['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open the original chapter</a></p>
        </div>
        <?php if (empty($u['hosting_allowed'])): ?>
          <div class="alert warn">You can only add pages for chapters whose source has hosting permission: written permission from the publisher or rights holder to show their chapters on Aduna.fit. If you have it, <?= $u['source_id'] && $u['source_name'] !== null ? '<a href="' . h(page_url('source_form', ['id' => $u['source_id']])) . '">edit the source</a> and tick "Hosting permission"' : 'add the publisher as a source and tick "Hosting permission"' ?>. Visitors see a "Visit original source" link until then.</div>
        <?php else: ?>
          <form class="card form" method="post" enctype="multipart/form-data" action="<?= h(page_url('pages_upload', ['id' => $u['id']])) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <label>Add pages (JPG, PNG, WEBP or GIF)
              <input type="file" name="pages[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" required>
            </label>
            <p class="small muted">Pages are added after the existing ones, in file-name order (001.jpg, 002.jpg…). Only upload pages the publisher gave you permission to host, not pages saved from their website without it. Your server accepts <?= h(ini_get('max_file_uploads')) ?> files and <?= h(ini_get('post_max_size')) ?> per upload.</p>
            <div class="actions"><button class="btn">Upload</button></div>
          </form>
        <?php endif; ?>
        <?php if ($pages): ?>
          <div class="card">
            <div class="head"><h2>Current pages</h2>
              <details class="danger">
                <summary class="btn small ghost">Delete all pages</summary>
                <form method="post" action="<?= h(page_url('pages_clear')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn small bad">Yes, delete <?= count($pages) ?> page(s)</button></form>
              </details>
            </div>
            <div class="page-thumbs">
              <?php foreach ($pages as $i => $name): ?>
                <figure><img src="<?= h(page_url('page_image', ['id' => $u['id'], 'f' => $name])) ?>" alt="Page <?= $i + 1 ?>" loading="lazy"><figcaption><?= $i + 1 ?></figcaption></figure>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif;
        layout_end();
        break;

    case 'connectors':
        $all = connectors_all();
        $test = $_SESSION['test_result'] ?? null;
        unset($_SESSION['test_result']);
        $counts = [];
        foreach (db_all('SELECT connector, COUNT(*) AS n FROM title_sources GROUP BY connector') as $r) {
            $counts[$r['connector']] = (int) $r['n'];
        }
        layout_start('Connectors', 'connectors');
        ?>
        <div class="head"><h1>Connectors</h1></div>
        <p class="muted">Official APIs that are published for programs to use. Enabled connectors run with the scheduled check: they add new manhwa and manhua titles and chapters to the site, and match titles across sites so each one is stored once. A connector that fails is retried later and doesn't stop the others.</p>
        <?php if ($test && isset($all[$test['name'] ?? ''])): ?>
          <div class="card test <?= $test['ok'] ? 'pass' : 'fail' ?>">
            <h2>Test of <?= h(connector_label($test['name'])) ?>: <?= $test['ok'] ? 'works' : 'not working' ?></h2>
            <ul class="checks"><?php foreach ($test['lines'] as [$lvl, $txt]): ?><li class="<?= h($lvl) ?>"><?= h($txt) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
        <div class="sources">
        <?php foreach ($all as $name => $c): $def = $c['def']; $st = json_decode((string) $c['last_stats'], true) ?: []; ?>
          <article class="card source">
            <div class="source-head">
              <div><h2><?= h($def['label']) ?></h2><a class="url" href="<?= h($def['docs']) ?>" target="_blank" rel="noopener noreferrer">API terms and docs</a></div>
              <?= $c['enabled'] ? ((int) $c['fail_count'] > 0 ? badge('Enabled · failing', 'warn') : badge('Enabled', 'ok')) : badge('Off', 'muted') ?>
            </div>
            <p class="small"><?= h($def['does']) ?></p>
            <p class="small muted"><b>Terms:</b> <?= h($def['terms']) ?></p>
            <dl class="facts">
              <div><dt>Every</dt><dd><?= connector_interval($name) ?> min</dd></div>
              <div><dt>Last run</dt><dd><?= h(ago($c['last_run_at'])) ?></dd></div>
              <div><dt>Last success</dt><dd><?= h(ago($c['last_success_at'])) ?></dd></div>
              <div><dt>Next run</dt><dd><?= $c['enabled'] ? h(ago($c['next_run_at'] ?? now())) : '—' ?></dd></div>
              <div><dt>Titles linked</dt><dd><?= $counts[$name] ?? 0 ?></dd></div>
              <div><dt>Last result</dt><dd><?= $st ? (int) $st['titles'] . ' new · ' . (int) $st['new'] . ' ch. · ' . (int) $st['requests'] . ' req.' : '—' ?></dd></div>
            </dl>
            <?php if ($c['last_error']): ?><p class="small error-text">Last error<?= (int) $c['fail_count'] ? ' (' . (int) $c['fail_count'] . ' in a row)' : '' ?>: <?= h($c['last_error']) ?></p><?php endif; ?>
            <div class="actions">
              <form method="post" action="<?= h(page_url('connector_test')) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= h($name) ?>"><button class="btn small">Test</button></form>
              <?php if ($c['enabled']): ?>
                <form method="post" action="<?= h(page_url('connector_run')) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= h($name) ?>"><button class="btn small ghost">Run now</button></form>
                <form method="post" action="<?= h(page_url('connector_disable')) ?>"><?= csrf_field() ?><input type="hidden" name="name" value="<?= h($name) ?>"><button class="btn small ghost">Disable</button></form>
              <?php else: ?>
                <form method="post" action="<?= h(page_url('connector_enable')) ?>" class="enable-form"><?= csrf_field() ?><input type="hidden" name="name" value="<?= h($name) ?>">
                  <label class="check small"><input type="checkbox" name="terms" value="1" <?= $c['terms_accepted'] ? 'checked' : '' ?>> I accept <?= h($def['label']) ?>'s terms above</label>
                  <button class="btn small ghost">Enable</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <?php
        layout_end();
        break;

    case 'titles':
        $q = trim((string) ($_GET['q'] ?? ''));
        $p = max(1, (int) ($_GET['p'] ?? 1));
        $per = 50;
        $where = $q !== '' ? 'WHERE t.title LIKE ? OR t.alt_titles LIKE ?' : '';
        $params = $q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : [];
        $total = (int) (db_one("SELECT COUNT(*) AS n FROM titles t $where", $params)['n'] ?? 0);
        $rows = db_all("SELECT t.*, (SELECT COUNT(*) FROM updates u WHERE u.title_id = t.id) AS chapters FROM titles t $where ORDER BY t.created_at DESC, t.id DESC LIMIT $per OFFSET " . (($p - 1) * $per), $params);
        layout_start('Titles', 'titles');
        ?>
        <div class="head"><h1>Titles <small class="muted">(<?= $total ?>)</small></h1></div>
        <p class="muted">Titles the connectors found. Each one is shown on the site at <code>title/?s=…</code>, and in "New titles" on the home page. <b>Hide</b> removes a title and its chapters from the website.</p>
        <form class="search" method="get"><input type="hidden" name="page" value="titles"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search titles"><button class="btn small">Search</button></form>
        <div class="card table-wrap">
          <table>
            <thead><tr><th>Title</th><th>Type</th><th>Latest</th><th>Sources</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6" class="muted">No titles yet. Enable a connector on the Connectors page.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $srcs = array_column(db_all('SELECT connector FROM title_sources WHERE title_id = ?', [$r['id']]), 'connector'); ?>
              <tr class="<?= $r['hidden'] ? 'hidden-row' : '' ?>">
                <td><a href="<?= h('../title/?s=' . rawurlencode($r['slug'])) ?>" target="_blank" rel="noopener"><?= h($r['title']) ?></a></td>
                <td><?= h($r['type'] ?? '—') ?></td>
                <td class="nowrap"><?= $r['latest_chapter'] !== null ? 'Ch. ' . h(format_chapter($r['latest_chapter'])) . ' <span class="muted small">(' . (int) $r['chapters'] . ')</span>' : '—' ?></td>
                <td class="small"><?= h(implode(', ', array_map('connector_label', $srcs))) ?></td>
                <td class="nowrap"><?= h(ago($r['created_at'])) ?></td>
                <td><form method="post" action="<?= h(page_url($r['hidden'] ? 'title_show' : 'title_hide')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><button class="link"><?= $r['hidden'] ? 'Show' : 'Hide' ?></button></form></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($total > $per): ?>
          <nav class="pager">
            <?php if ($p > 1): ?><a href="<?= h(page_url('titles', ['q' => $q, 'p' => $p - 1])) ?>">« Newer</a><?php endif; ?>
            <span>Page <?= $p ?> of <?= (int) ceil($total / $per) ?></span>
            <?php if ($p * $per < $total): ?><a href="<?= h(page_url('titles', ['q' => $q, 'p' => $p + 1])) ?>">Older »</a><?php endif; ?>
          </nav>
        <?php endif;
        layout_end();
        break;

    case 'series_info':
        $q = trim((string) ($_GET['q'] ?? ''));
        $found = null;
        if ($q !== '') {
            @set_time_limit(30);
            $found = anilist_search($q);
        }
        layout_start('Series info', 'series_info');
        ?>
        <div class="head"><h1>Series info <small class="muted">from AniList</small></h1></div>
        <p class="muted">Look up a series' details (title, type, genres, status, year, author, rating and synopsis) on <a href="https://anilist.co" target="_blank" rel="noopener noreferrer">AniList</a>'s official API, ready to paste as a new series file. Nothing is saved and no images are copied. Searches are only made when you click <b>Search</b>.</p>
        <form class="search" method="get"><input type="hidden" name="page" value="series_info"><input type="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="Series name, e.g. Solo Leveling" required><button class="btn small">Search</button></form>
        <?php if ($found && $found['error']): ?><div class="alert error"><?= h($found['error']) ?></div><?php endif; ?>
        <?php if ($found && !$found['error'] && !$found['results']): ?><div class="card empty">No series found on AniList for "<?= h($q) ?>".</div><?php endif; ?>
        <?php foreach ($found['results'] ?? [] as $r): $d = $r['series']; ?>
          <article class="card source">
            <div class="source-head">
              <div>
                <h2><?= h($d['title']) ?></h2>
                <a class="url" href="<?= h($d['source']['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($d['source']['url']) ?></a>
              </div>
              <?= badge($d['type'], 'muted') ?>
            </div>
            <dl class="facts">
              <div><dt>Status</dt><dd><?= h($d['status']) ?></dd></div>
              <div><dt>Year</dt><dd><?= (int) $d['releaseYear'] ?></dd></div>
              <div><dt>Rating</dt><dd><?= isset($d['rating']) ? h((string) $d['rating']) . ' / 5' : '—' ?></dd></div>
              <div><dt>Author</dt><dd><?= h($d['author'] ?? '—') ?></dd></div>
            </dl>
            <label class="small">Save as <code>src/content/series/<?= h($r['slug']) ?>.json</code>
              <textarea class="json" rows="12" readonly><?= h(series_json($d)) ?></textarea>
            </label>
          </article>
        <?php endforeach; ?>
        <p class="muted small">How to add one to your site: on GitHub, open <code>src/content/series</code>, click <b>Add file → Create new file</b>, name it as shown, paste the text, add chapters if you have them, and commit. Rewrite the synopsis in your own words if it was quoted from a publisher. The series page credits AniList as the source. Series data from AniList is free for non-commercial sites.</p>
        <?php
        layout_end();
        break;

    case 'logs':
        $level = (string) ($_GET['level'] ?? '');
        $rows = in_array($level, ['info', 'warning', 'error'], true)
            ? db_all('SELECT l.*, s.name AS source_name FROM logs l LEFT JOIN sources s ON s.id = l.source_id WHERE l.level = ? ORDER BY l.id DESC LIMIT 300', [$level])
            : db_all('SELECT l.*, s.name AS source_name FROM logs l LEFT JOIN sources s ON s.id = l.source_id ORDER BY l.id DESC LIMIT 300');
        layout_start('Logs', 'logs');
        ?>
        <div class="head">
          <h1>Logs</h1>
          <form method="post" action="<?= h(page_url('logs_clear')) ?>"><?= csrf_field() ?><button class="btn ghost small">Clear logs</button></form>
        </div>
        <nav class="filters">
          <?php foreach (['' => 'All', 'error' => 'Errors', 'warning' => 'Warnings', 'info' => 'Info'] as $k => $label): ?>
            <a class="<?= $level === $k ? 'active' : '' ?>" href="<?= h(page_url('logs', $k ? ['level' => $k] : [])) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="card table-wrap">
          <table>
            <thead><tr><th>When (UTC)</th><th>Level</th><th>Source</th><th>Message</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing logged yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr><td class="nowrap"><?= h($r['created_at']) ?></td><td><?= badge($r['level'], $r['level'] === 'error' ? 'bad' : ($r['level'] === 'warning' ? 'warn' : 'muted')) ?></td><td><?= h($r['source_name'] ?? '') ?></td><td><?= h($r['message']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted small">Logs older than <?= (int) config('log_retention_days') ?> days are deleted automatically.</p>
        <?php
        layout_end();
        break;

    default: // dashboard
        $counts = db_one('SELECT
            (SELECT COUNT(*) FROM sources) AS sources,
            (SELECT COUNT(*) FROM sources WHERE enabled = 1) AS enabled,
            (SELECT COUNT(*) FROM updates) AS updates,
            (SELECT COUNT(*) FROM updates WHERE created_at >= ?) AS today,
            (SELECT COUNT(*) FROM logs WHERE level = \'error\' AND created_at >= ?) AS errors,
            (SELECT COUNT(*) FROM titles) AS titles,
            (SELECT COUNT(*) FROM connectors WHERE enabled = 1) AS connectors', [gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s', time() - 86400)]);
        $lastRun = meta_get('last_run_at');
        $recent = db_all('SELECT u.*, s.name AS source_name FROM updates u LEFT JOIN sources s ON s.id = u.source_id ORDER BY u.id DESC LIMIT 10');
        $cronStale = !$lastRun || ts($lastRun) < time() - 3 * 3600;
        $failing = db_all('SELECT name, last_error FROM connectors WHERE enabled = 1 AND fail_count > 0');
        layout_start('Dashboard', 'dashboard');
        ?>
        <div class="head">
          <h1>Dashboard</h1>
          <form method="post" action="<?= h(page_url('run_all')) ?>"><?= csrf_field() ?><button class="btn">Check all enabled sources now</button></form>
        </div>
        <?php if (pages_missing()): ?>
          <div class="alert warn">The file <code>admin/lib/pages.php</code> is missing on your server, so uploading chapter pages is switched off. Everything else works. Your host's virus scanner may have removed it: see "Troubleshooting" in the install guide.</div>
        <?php endif; ?>
        <?php if ($cronStale): ?>
          <div class="alert warn">The scheduled check hasn't run <?= $lastRun ? 'for over 3 hours' : 'yet' ?>. Set up the cron job (see the install guide) so sources are checked automatically.</div>
        <?php endif; ?>
        <?php foreach ($failing as $f): ?>
          <div class="alert warn"><?= h(connector_label($f['name'])) ?> is failing (<?= h(rtrim((string) $f['last_error'], '.')) ?>). It will retry automatically. See <a href="<?= h(page_url('connectors')) ?>">Connectors</a>.</div>
        <?php endforeach; ?>
        <div class="stats">
          <div class="card stat"><b><?= (int) $counts['enabled'] ?> / <?= (int) $counts['sources'] ?></b><span>sources enabled</span></div>
          <div class="card stat"><b><?= (int) $counts['connectors'] ?> / <?= count(connector_defs()) ?></b><span>connectors enabled</span></div>
          <div class="card stat"><b><?= (int) $counts['titles'] ?></b><span>titles found</span></div>
          <div class="card stat"><b><?= (int) $counts['updates'] ?></b><span>updates stored</span></div>
          <div class="card stat"><b><?= (int) $counts['today'] ?></b><span>new in last 24 h</span></div>
          <div class="card stat <?= $counts['errors'] ? 'has-errors' : '' ?>"><b><?= (int) $counts['errors'] ?></b><span>errors in last 24 h</span></div>
        </div>
        <p class="muted">Last scheduled run: <?= h(ago($lastRun)) ?>. Last website refresh: <?= h(ago(meta_get('last_publish_at'))) ?>.</p>
        <section class="card table-wrap">
          <h2>Newest updates</h2>
          <table>
            <thead><tr><th>Series</th><th>Chapter</th><th>Source</th><th>Found</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?><tr><td colspan="4" class="muted">No updates yet. Add a source, test it, enable it, and set up the cron job.</td></tr><?php endif; ?>
            <?php foreach ($recent as $r): ?>
              <tr><td><?= h($r['series_title']) ?></td><td><a href="<?= h($r['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Ch. <?= h(format_chapter($r['chapter_number'])) ?></a></td><td><?= h($r['connector'] ? connector_label($r['connector']) : ($r['source_name'] ?? '')) ?></td><td class="nowrap"><?= h(ago($r['created_at'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </section>
        <?php
        layout_end();
}
