// Copies the finished blog build (blog/dist/) into deploy/, the folder cPanel copies to the site folder.
// deploy/ only ever contains website files: no source code, .git, .env or node_modules.
// The blog admin (blog/public/admin/ and post.php) comes along with the build, like every file in public/.
import fs from 'node:fs';
import path from 'node:path';
import site from '../site.config.mjs';

const root = path.resolve(import.meta.dirname, '..', '..');
const dist = path.join(root, 'blog', 'dist');
const deploy = path.join(root, 'deploy');

if (!fs.existsSync(path.join(dist, 'index.html'))) {
  console.error('blog/dist/index.html is missing. Did the build fail?');
  process.exit(1);
}

fs.rmSync(deploy, { recursive: true, force: true });
fs.cpSync(dist, deploy, { recursive: true });

// The post layout and site data for the admin are built as pages; move them into admin/inc/,
// where visitors can't open them, so they never show up as pages of the site.
const inc = path.join(deploy, 'admin', 'inc');
fs.mkdirSync(inc, { recursive: true });
fs.renameSync(path.join(deploy, 'admin-layout', 'index.html'), path.join(inc, 'layout.html'));
fs.rmSync(path.join(deploy, 'admin-layout'), { recursive: true });
fs.renameSync(path.join(deploy, 'admin-site.json'), path.join(inc, 'site.json'));
fs.renameSync(path.join(deploy, 'admin-code-posts.json'), path.join(inc, 'code-posts.json'));

// Fill in the site folder in .htaccess (e.g. /AbroadPath/).
const base = site.base ? `${site.base.replace(/\/$/, '')}/` : '/';
const htaccess = path.join(deploy, '.htaccess');
fs.writeFileSync(htaccess, fs.readFileSync(htaccess, 'utf8').replaceAll('__BASE__', base));

// Safety net: refuse to ship anything that looks private.
const banned = [/^\.git$/, /^\.env/, /^node_modules$/, /^\.cpanel\.yml$/, /^package(-lock)?\.json$/, /^src$/];
const walk = (dir) =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (banned.some((r) => r.test(e.name))) throw new Error(`Refusing to deploy private file: ${path.relative(root, p)}`);
    return e.isDirectory() ? walk(p) : [p];
  });
const files = walk(deploy);
const bytes = files.reduce((n, f) => n + fs.statSync(f).size, 0);

console.log(`\ndeploy/ is ready: ${files.length} files, ${(bytes / 1024 / 1024).toFixed(1)} MB`);
console.log(`It will live at ${site.url}${base}`);
