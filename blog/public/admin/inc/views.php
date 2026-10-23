<?php
// The admin's screens.
defined('ABROADPATH') || exit;

function admin_url(string $action = '', array $params = []): string
{
    $q = $action === '' ? $params : ['a' => $action] + $params;
    return url('admin/') . ($q ? '?' . http_build_query($q) : '');
}

const LOGO = '<svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#0d6e5a"/><path d="M7 21.5 15.5 9l3.2 4.7L21 11l4 10.5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="21" cy="7.5" r="2" fill="#f5b83d"/></svg>';

function page(string $title, string $body, bool $signedIn = true): string
{
    $v = ADMIN_VERSION;
    $nav = '';
    if ($signedIn) {
        $nav = '<nav class="bar-nav"><a href="' . h(url()) . '" target="_blank" rel="noopener">View site</a>'
            . '<a href="' . h(admin_url('password')) . '">Password</a>'
            . '<form method="post" action="' . h(admin_url('logout')) . '">' . csrf_field() . '<button type="submit" class="link">Sign out</button></form></nav>';
    }
    $flash = '';
    foreach (flash() as [$type, $msg]) {
        $flash .= '<p class="flash ' . h($type) . '" role="status">' . h($msg) . '</p>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' · ' . h(site('name')) . ' admin</title>'
        . '<link rel="icon" href="' . h(url('favicon.svg')) . '" type="image/svg+xml">'
        . '<link rel="stylesheet" href="' . h(url("admin/assets/admin.css?v=$v")) . '"></head><body>'
        . '<header class="bar"><a class="brand" href="' . h(admin_url()) . '">' . LOGO . '<span>' . h(site('name')) . ' <b>admin</b></span></a>' . $nav . '</header>'
        . '<main class="wrap">' . $flash . $body . '</main>'
        . '<script src="' . h(url("admin/assets/admin.js?v=$v")) . '" defer></script></body></html>';
}

function error_text(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="error" id="err-' . h($field) . '">' . h($errors[$field]) . '</span>' : '';
}

function view_problem(string $title, string $html): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . '</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;line-height:1.6">'
        . '<h1>' . h($title) . '</h1>' . $html . '</body></html>';
}

function view_setup(?string $error): string
{
    $dir = private_dir();
    $body = '<section class="card narrow"><h1>Set up your admin</h1>'
        . '<p>This is the first visit, so you need to choose the admin password. To prove it\'s you, the admin wrote a setup code into a file on your server that only you can open:</p>'
        . '<ol class="steps"><li>Open cPanel, then <b>File Manager</b>.</li>'
        . '<li>In your home folder (the one with <b>public_html</b> and <b>aduna.fit</b> in it), open the folder <b>' . h(basename($dir)) . '</b>.</li>'
        . '<li>Open <b>setup-code.txt</b> and copy the code.</li></ol>'
        . ($error ? '<p class="flash error">' . h($error) . '</p>' : '')
        . '<form method="post" action="' . h(admin_url('setup')) . '" class="stack">' . csrf_field()
        . '<label>Setup code<input name="code" required autocomplete="off" autocapitalize="characters" placeholder="ABCD-EFGH-JKLM"></label>'
        . '<label>New password<input type="password" name="password" required minlength="' . PASSWORD_MIN . '" autocomplete="new-password"></label>'
        . '<label>Type it again<input type="password" name="repeat" required minlength="' . PASSWORD_MIN . '" autocomplete="new-password"></label>'
        . '<p class="hint">At least ' . PASSWORD_MIN . ' characters. A short sentence you\'ll remember works well.</p>'
        . '<button type="submit" class="btn primary">Save password and sign in</button></form></section>';
    return page('Set up', $body, false);
}

function view_login(?string $error): string
{
    $body = '<section class="card narrow"><h1>Sign in</h1>'
        . ($error ? '<p class="flash error">' . h($error) . '</p>' : '')
        . '<form method="post" action="' . h(admin_url('login')) . '" class="stack">' . csrf_field()
        . '<label>Password<input type="password" name="password" required autofocus autocomplete="current-password"></label>'
        . '<button type="submit" class="btn primary">Sign in</button></form>'
        . '<p class="hint">Forgot it? In cPanel File Manager, delete <b>config.php</b> in the folder <b>' . h(basename(private_dir())) . '</b> in your home folder, then reload this page to set a new one. Your posts stay as they are.</p></section>';
    return page('Sign in', $body, false);
}

function status_badge(array $p): string
{
    return $p['status'] === 'published' ? '<span class="badge live">Published</span>' : '<span class="badge">Draft</span>';
}

/** Where a post can be seen: its address once published, and post.php for a draft's preview. */
function preview_url(array $p): string
{
    return $p['status'] === 'published' ? post_path($p['slug']) : url('post.php?slug=' . rawurlencode($p['slug']));
}

function view_list(): string
{
    $posts = admin_posts();
    $body = '<div class="page-head"><h1>Your posts</h1><a class="btn primary" href="' . h(admin_url('new')) . '">+ New post</a></div>';
    if (!$posts) {
        $body .= '<section class="card empty"><p><b>No posts written here yet.</b> Write one with <b>New post</b>, or edit one of the posts already on the site below. A post goes live as soon as you press Publish.</p></section>';
    } else {
        $body .= '<ul class="post-list">';
        foreach ($posts as $p) {
            $view = $p['status'] === 'published' ? 'View' : 'Preview';
            $body .= '<li><div class="post-row"><a class="post-title" href="' . h(admin_url('edit', ['id' => $p['id']])) . '">' . h($p['title']) . '</a>'
                . '<p class="meta">' . status_badge($p) . ($p['from_code'] ? ' <span class="badge">Edited post from the code</span>' : '')
                . ' ' . h(category_name($p['category'])) . ' · changed ' . h(format_date($p['updated_at'])) . '</p></div>'
                . '<div class="row-actions"><a class="btn small" href="' . h(admin_url('edit', ['id' => $p['id']])) . '">Edit</a>'
                . '<a class="btn small ghost" href="' . h(preview_url($p)) . '" target="_blank" rel="noopener">' . $view . '</a></div></li>';
        }
        $body .= '</ul>';
    }
    // The posts in the code that haven't been edited here yet
    $mine = array_column($posts, 'slug', 'slug');
    $code = array_filter(site('posts'), fn ($p) => !isset($mine[$p['slug']]));
    if ($code) {
        $body .= '<h2 class="list-title">Posts in the site\'s code (' . count($code) . ')</h2>'
            . '<p class="hint">Click Edit to change one here. The site keeps showing the original until you publish your changes, and the original stays in the code, so you can always go back to it.</p>'
            . '<ul class="post-list">';
        foreach ($code as $p) {
            $edit = admin_url('new', ['from' => $p['slug']]);
            $body .= '<li><div class="post-row"><a class="post-title" href="' . h($edit) . '">' . h($p['title']) . '</a>'
                . '<p class="meta"><span class="badge live">Published</span> ' . h(category_name($p['category'])) . ' · ' . h(format_date($p['updated'] ?? $p['date'])) . '</p></div>'
                . '<div class="row-actions"><a class="btn small" href="' . h($edit) . '">Edit</a>'
                . '<a class="btn small ghost" href="' . h(post_path($p['slug'])) . '" target="_blank" rel="noopener">View</a></div></li>';
        }
        $body .= '</ul>';
    }
    return page('Posts', $body);
}

function view_editor(array $p, array $errors = []): string
{
    $isNew = empty($p['id']);
    $published = ($p['status'] ?? 'draft') === 'published';
    $fromCode = !empty($p['from_code']);
    $action = admin_url('save', $isNew ? [] : ['id' => $p['id']]);
    $sel = fn (bool $on) => $on ? ' selected' : '';
    $chk = fn (bool $on) => $on ? ' checked' : '';

    $cats = '';
    foreach (site('categories') as $c) {
        $cats .= '<option value="' . h($c['slug']) . '"' . $sel($c['slug'] === $p['category']) . '>' . h($c['name']) . '</option>';
    }
    $countries = '';
    foreach (site('countries') as $c) {
        $countries .= '<label class="check"><input type="checkbox" name="countries[]" value="' . h($c['slug']) . '"' . $chk(in_array($c['slug'], $p['countries'], true)) . '> ' . h($c['flag'] . ' ' . $c['name']) . '</label>';
    }
    $facts = '';
    $rows = $p['facts'];
    while (count($rows) < 3) {
        $rows[] = ['label' => '', 'value' => ''];
    }
    foreach ($rows as $f) {
        $facts .= '<div class="fact-row"><input name="fact_label[]" value="' . h($f['label']) . '" placeholder="Label, e.g. Level" aria-label="Fact label" maxlength="60">'
            . '<input name="fact_value[]" value="' . h($f['value']) . '" placeholder="Value, e.g. Master\'s" aria-label="Fact value" maxlength="200"></div>';
    }

    $unpublish = $fromCode
        ? '<button class="btn ghost" name="action" value="unpublish" data-confirm="Put the original version from the code back on the site? Your changes stay here as a draft.">Show the original</button>'
        : '<button class="btn ghost" name="action" value="unpublish" data-confirm="Take this post off the site? It stays here as a draft.">Unpublish</button>';
    $buttons = $published
        ? '<button class="btn primary" name="action" value="update">Update</button>' . $unpublish
        : '<button class="btn primary" name="action" value="publish">' . ($fromCode ? 'Publish changes' : 'Publish') . '</button><button class="btn ghost" name="action" value="draft">Save draft</button>';
    $view = '';
    if (!$isNew) {
        $view = '<a class="view-link" href="' . h(preview_url($p)) . '" target="_blank" rel="noopener">'
            . ($published ? 'View the post on the site' : 'Preview the last saved version') . ' ↗</a>';
    }
    if ($fromCode && !$published) {
        $view .= '<a class="view-link" href="' . h(post_path($p['slug'])) . '" target="_blank" rel="noopener">See the original on the site ↗</a>';
    }
    $note = '';
    if ($fromCode) {
        $note = '<p class="flash note">' . ($published
            ? 'This post is from the site\'s code. The site shows your edited version. <b>Show the original</b> puts the version from the code back.'
            : 'This post is from the site\'s code. The site keeps showing the original until you click <b>Publish changes</b>, and the original stays in the code, so you can always go back to it.')
            . '</p>';
    }
    $cover = '';
    if (!empty($p['cover'])) {
        $cover = '<figure class="cover-now"><img src="' . h(media_url($p['cover'])) . '" alt="Current cover image"></figure>'
            . '<label class="check"><input type="checkbox" name="remove_cover" value="1"> Remove this image</label>';
    }
    $limit = round(upload_limit() / 1048576, 1);
    $body = '<div class="page-head"><h1>' . ($isNew && !$fromCode ? 'New post' : 'Edit post') . '</h1><a href="' . h(admin_url()) . '">← All posts</a></div>'
        . ($errors ? '<p class="flash error" role="alert">Some things need fixing before this can be saved. They\'re marked in red below.</p>' : '') . $note
        . '<form class="editor" method="post" enctype="multipart/form-data" action="' . h($action) . '" data-editor'
        . ' data-upload="' . h(admin_url('upload')) . '" data-csrf="' . h(csrf_token()) . '" data-max-mb="' . h((string) $limit) . '" data-new="' . ($isNew && !$fromCode ? '1' : '0') . '"'
        . ($fromCode && $published ? ' data-check-live="' . h(post_path($p['slug'])) . '"' : '') . '>' . csrf_field()
        . ($isNew && $fromCode ? '<input type="hidden" name="from_code" value="' . h($p['slug']) . '">' : '')
        . '<div class="editor-main">'
        . '<label class="title-field">Title<input name="title" value="' . h($p['title']) . '" required maxlength="200" placeholder="e.g. Chevening Scholarship 2027: who can apply and how"' . (isset($errors['title']) ? ' aria-invalid="true"' : '') . '></label>' . error_text($errors, 'title')
        . '<div class="field"><span class="label">Post</span>'
        . '<div class="toolbar" role="toolbar" aria-label="Formatting" hidden>'
        . '<select data-block aria-label="Text style"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Subheading</option></select>'
        . '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>'
        . '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>'
        . '<button type="button" data-cmd="link" title="Add a link">Link</button>'
        . '<button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>'
        . '<button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>'
        . '<button type="button" data-cmd="quote" title="Quote">“ Quote</button>'
        . '<button type="button" data-cmd="image" title="Add an image">Image</button>'
        . '<button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>'
        . '<button type="button" data-cmd="undo" title="Undo">↶</button><button type="button" data-cmd="redo" title="Redo">↷</button>'
        . '</div>'
        . '<div class="rte prose" data-rte hidden></div>'
        . '<textarea name="body" class="rte-source" rows="16" placeholder="Write the post here. Leave an empty line between paragraphs.">' . h($p['body']) . '</textarea>'
        . '<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-image-input hidden>'
        . '<p class="hint">Ads go in by themselves after the 2nd paragraph (and lower down in long posts). Images: JPG, PNG, WEBP or GIF up to ' . h((string) $limit) . ' MB.</p>'
        . error_text($errors, 'body') . '</div>'
        . '<label>Summary<textarea name="description" rows="3" maxlength="300" data-count="160" placeholder="One or two sentences on what readers will find. Google shows this under the title."' . (isset($errors['description']) ? ' aria-invalid="true"' : '') . '>' . h($p['description']) . '</textarea></label>'
        . '<span class="hint" data-count-for="description"></span>' . error_text($errors, 'description')
        . '</div>'
        . '<aside class="editor-side">'
        . '<section class="box"><p class="box-title">' . status_badge($p + ['status' => 'draft']) . '</p><div class="buttons">' . $buttons . '</div>' . $view . '</section>'
        . '<section class="box"><label>Category<select name="category">' . $cats . '</select></label>' . error_text($errors, 'category')
        . '<fieldset><legend>For readers in</legend><div class="checks">' . $countries . '</div><p class="hint">Tick none if it suits everyone.</p></fieldset></section>'
        . '<section class="box"><span class="label">Cover image</span>' . $cover
        . '<input type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" data-cover-input>'
        . '<p class="hint">Shown at the top of the post and when it\'s shared on WhatsApp or Facebook. 1200×630 works best.</p>' . error_text($errors, 'cover') . '</section>'
        . '<section class="box"><span class="label">Key facts box</span><div class="facts" data-facts>' . $facts . '</div>'
        . '<button type="button" class="btn small ghost" data-add-fact>+ Add a fact</button>' . error_text($errors, 'facts')
        . '<label>Deadline<input type="date" name="deadline" value="' . h((string) $p['deadline']) . '"></label>' . error_text($errors, 'deadline')
        . '<p class="hint">Posts with a deadline also show on the Deadlines page and under "Closing soon".</p>'
        . '<label>Official page name<input name="official_name" value="' . h($p['official_name']) . '" maxlength="120" placeholder="e.g. Chevening"></label>' . error_text($errors, 'official_name')
        . '<label>Official page link<input type="url" name="official_url" value="' . h($p['official_url']) . '" placeholder="https://"></label>' . error_text($errors, 'official_url')
        . '<label>Facts checked on<input type="date" name="checked" value="' . h($p['checked']) . '" required></label>' . error_text($errors, 'checked')
        . '</section>'
        . '<section class="box"><label>Web address<span class="slug"><span class="slug-base">' . h(url('blog/')) . '</span><input name="slug" value="' . h($p['slug']) . '" maxlength="80" pattern="[a-z0-9\\-]*" data-slug' . ($fromCode ? ' readonly' : '') . (isset($errors['slug']) ? ' aria-invalid="true"' : '') . '></span></label>'
        . '<p class="hint">' . ($fromCode ? 'This post keeps its address, so links to it keep working.' : 'Made from the title. Changing it after publishing breaks links people already shared.') . '</p>' . error_text($errors, 'slug') . '</section>'
        . '</aside></form>';
    if (!$isNew) {
        $body .= '<form method="post" action="' . h(admin_url('delete', ['id' => $p['id']])) . '" class="danger-zone" data-confirm="'
            . ($fromCode ? 'Delete your changes to this post? The original from the code goes back on the site.' : 'Delete this post for good? This can\'t be undone.') . '">'
            . csrf_field() . '<button type="submit" class="btn danger">' . ($fromCode ? 'Delete my changes' : 'Delete post') . '</button></form>';
    }
    return page($isNew ? 'New post' : 'Edit: ' . $p['title'], $body);
}

function view_password(?string $error): string
{
    $body = '<div class="page-head"><h1>Change password</h1><a href="' . h(admin_url()) . '">← All posts</a></div><section class="card narrow">'
        . ($error ? '<p class="flash error">' . h($error) . '</p>' : '')
        . '<form method="post" action="' . h(admin_url('password')) . '" class="stack">' . csrf_field()
        . '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label>New password<input type="password" name="password" required minlength="' . PASSWORD_MIN . '" autocomplete="new-password"></label>'
        . '<label>Type it again<input type="password" name="repeat" required minlength="' . PASSWORD_MIN . '" autocomplete="new-password"></label>'
        . '<p class="hint">Any other phone or computer signed in to the admin is signed out.</p>'
        . '<button type="submit" class="btn primary">Change password</button></form></section>';
    return page('Password', $body);
}
