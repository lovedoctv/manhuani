// The import pipeline for one series from one source:
//   discover chapters → fetch each chapter's page list → get and check the pages →
//   save them in public/chapters/<series>/<chapter>/ → update src/content/series/<series>.json
//
// Safe to run again: chapters whose pages haven't changed are skipped, a chapter that fails
// keeps whatever it had before, and each finished chapter is saved straight away, so an
// interrupted run picks up where it stopped.
import fs from 'node:fs';
import path from 'node:path';
import { checkPage, pageFileName, sha256, DEFAULT_MAX_PAGE_BYTES } from './assets.mjs';
import { isBlockedHost } from './sources.mjs';
import { safeUrl } from './http.mjs';
import * as store from './store.mjs';

const validDate = (value) => {
  if (value === undefined || value === null || value === '') return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
};

const httpLink = (value) => {
  try {
    const u = new URL(value);
    return /^https?:$/.test(u.protocol) && !isBlockedHost(value) ? u.href : null;
  } catch {
    return null;
  }
};

/** True when the file recorded in the state is still there, unchanged. */
const fileMatches = (root, f) => {
  const p = path.join(root, f.path);
  if (!fs.existsSync(p) || fs.statSync(p).size !== f.size) return false;
  return sha256(fs.readFileSync(p)) === f.sha256;
};

/** Only paths the importer itself manages may be replaced or removed. */
const insideChapters = (rel, slug) => !rel.includes('..') && rel.startsWith(`public/chapters/${slug}/`);

export async function importSeries({
  root, source, slug, adapter, http, log, runId,
  dryRun = false, force = false, updateMetadata = false, chapterFilter = null, now = new Date(),
}) {
  const mode = source.assets ?? 'copy';
  const maxBytes = source.maxPageBytes ?? DEFAULT_MAX_PAGE_BYTES;
  const summary = {
    source: source.id, series: slug, dryRun, mode,
    discovered: 0, imported: 0, unchanged: 0, noPages: 0, skipped: 0, failed: 0,
    chapters: [], changed: [],
  };
  const note = (number, status, detail = {}) => summary.chapters.push({ number, status, ...detail });
  const ctx = { root, source, slug, config: source.series[slug], http, log };

  // 1. Discover the series and its chapters. If this fails, nothing is changed.
  let found;
  try {
    found = await adapter.discover(ctx);
    if (!found || !Array.isArray(found.chapters)) throw new Error('the source returned no chapter list');
  } catch (e) {
    summary.fatal = `couldn't read the series from ${source.name}: ${e.message}`;
    log.error('series.failed', { series: slug, reason: e.message });
    return summary;
  }

  const series = store.loadSeries(root, slug);
  const isNewSeries = !series.data;
  if (isNewSeries) {
    try {
      series.data = store.newSeries(found.series, now);
    } catch (e) {
      summary.fatal = e.message;
      log.error('series.failed', { series: slug, reason: e.message });
      return summary;
    }
    log.info('series.new', { series: slug, title: series.data.title });
  }
  if (!Array.isArray(series.data.chapters)) series.data.chapters = [];
  const state = store.loadState(root, source.id, slug);
  const staging = path.join(root, 'crawler', '.staging', runId);

  const save = () => {
    if (dryRun || (isNewSeries && !series.data.chapters.length)) return;
    store.saveSeries(series);
    store.saveState(state);
  };

  // Fills in chapter details. Details you already have are kept unless --update-metadata.
  const applyMeta = (record, info, isNewRecord) => {
    let changed = false;
    const title = typeof info.title === 'string' && info.title.trim() ? info.title.trim() : null;
    if (title && title !== record.title && (isNewRecord || !record.title || updateMetadata)) {
      record.title = title;
      changed = true;
    }
    const date = validDate(info.date);
    if (date && !isNewRecord && updateMetadata && validDate(record.date)?.getTime() !== date.getTime()) {
      record.date = date.toISOString();
      changed = true;
    }
    const link = info.sourceUrl ? httpLink(info.sourceUrl) : null;
    if (link && link !== record.sourceUrl && (!record.sourceUrl || updateMetadata)) {
      record.sourceUrl = link;
      changed = true;
    }
    return changed;
  };

  const newRecord = (number, info) => ({
    number,
    date: (validDate(info.date) ?? now).toISOString(),
    images: [],
  });

  // 2. One entry per chapter number, oldest first.
  const byNumber = new Map();
  for (const ch of found.chapters) {
    const n = Number(ch?.number);
    if (!Number.isFinite(n) || n < 0) {
      summary.skipped++;
      note(ch?.number ?? null, 'skipped', { reason: 'no valid chapter number' });
      log.warn('chapter.skipped', { chapter: ch?.number ?? null, reason: 'no valid chapter number' });
      continue;
    }
    const key = store.chapterKey(n);
    if (byNumber.has(key)) {
      summary.skipped++;
      note(n, 'skipped', { reason: 'listed twice by the source; the first entry was used' });
      log.warn('chapter.duplicate', { chapter: n });
      continue;
    }
    byNumber.set(key, { ...ch, number: n });
  }
  const chapters = [...byNumber.values()].sort((a, b) => a.number - b.number);
  summary.discovered = chapters.length;
  log.info('series.discovered', { series: slug, source: source.id, chapters: chapters.length });

  let anyPages = false;

  // 3. Each chapter on its own: a failure here leaves the chapter exactly as it was.
  for (const ref of chapters) {
    if (chapterFilter && !chapterFilter(ref.number)) {
      summary.skipped++;
      note(ref.number, 'skipped', { reason: 'not in --chapters' });
      continue;
    }
    const key = store.chapterKey(ref.number);
    try {
      const data = await adapter.fetchChapter(ctx, ref);
      const info = { title: data.title ?? ref.title, date: data.date ?? ref.date, sourceUrl: data.sourceUrl ?? ref.sourceUrl };
      const existing = store.findChapter(series.data, ref.number);

      const seen = new Set();
      const pages = [];
      for (const p of data.pages ?? []) {
        const id = p.url ?? p.file;
        if (!id) continue;
        if (seen.has(id)) {
          log.warn('page.duplicate', { chapter: ref.number, page: p.name ?? safeUrl(p.url) });
          continue;
        }
        seen.add(id);
        pages.push(p);
      }

      // No pages from the source: keep any pages already there and only record the details.
      if (!pages.length) {
        const record = existing ?? newRecord(ref.number, info);
        const changed = applyMeta(record, info, !existing);
        if (!existing) store.insertChapter(series.data, record);
        if (existing?.images?.length) {
          log.warn('chapter.no-pages', { chapter: ref.number, reason: 'the source lists no pages; the pages already on the site were kept' });
          anyPages = true;
        }
        if (!existing || changed) {
          summary.changed.push(record);
          save();
        }
        summary.noPages++;
        note(ref.number, 'no pages', { reason: 'the source lists no pages for this chapter' });
        continue;
      }

      // Local files are read once and reused for the fingerprint and the copy.
      const fileCache = new Map();
      const readFile = (file) => {
        if (!fileCache.has(file)) fileCache.set(file, fs.readFileSync(file));
        return fileCache.get(file);
      };
      const fingerprint = sha256(JSON.stringify({
        mode,
        version: data.version ?? null,
        pages: pages.map((p) => p.url ?? sha256(readFile(p.file))),
      }));

      const prev = state.data.chapters[key];
      const intact = prev && (mode === 'link' || (prev.files ?? []).every((f) => fileMatches(root, f)));
      const sameImages = prev && existing && JSON.stringify(existing.images ?? []) === JSON.stringify(prev.images ?? []);
      if (!force && prev?.fingerprint === fingerprint && intact && sameImages) {
        if (applyMeta(existing, info, false)) {
          summary.changed.push(existing);
          save();
        }
        summary.unchanged++;
        anyPages = true;
        note(ref.number, 'unchanged', { pages: existing.images.length });
        log.info('chapter.unchanged', { chapter: ref.number, pages: existing.images.length });
        continue;
      }

      let images;
      let files = [];
      if (mode === 'link') {
        images = pages.map((p, i) => {
          if (p.url && isBlockedHost(p.url)) throw new Error(`page ${i + 1} is on ${new URL(p.url).hostname}, a site that re-hosts scans without permission`);
          const link = p.url && httpLink(p.url);
          if (!link) throw new Error(`page ${i + 1} has no usable https link`);
          return link;
        });
      } else {
        const dirName = store.chapterDir(ref.number);
        const got = [];
        for (const [i, page] of pages.entries()) {
          const label = page.name ?? safeUrl(page.url);
          let buf;
          try {
            buf = page.file ? readFile(page.file) : (await http.get(page.url, { maxBytes, accept: 'image/*' })).body;
          } catch (e) {
            throw new Error(`page ${i + 1} (${label}) couldn't be fetched: ${e.message}`);
          }
          let type;
          try {
            type = checkPage(buf, { maxBytes });
          } catch (e) {
            throw new Error(`page ${i + 1} (${label}) was rejected: ${e.message}`);
          }
          got.push({ buf, ext: type.ext });
        }
        files = got.map((g, i) => ({
          path: path.posix.join('public', 'chapters', slug, dirName, pageFileName(i, got.length, g.ext)),
          sha256: sha256(g.buf),
          size: g.buf.length,
        }));
        const repeats = files.filter((f, i) => i && f.sha256 === files[i - 1].sha256).length;
        if (repeats) log.warn('page.repeated', { chapter: ref.number, count: repeats, reason: 'a page is identical to the one before it' });

        // Never overwrite a file the importer didn't make.
        const ours = new Set((prev?.files ?? []).map((f) => f.path));
        for (const f of files) {
          const target = path.join(root, f.path);
          if (fs.existsSync(target) && !ours.has(f.path) && !fileMatches(root, f)) {
            throw new Error(`${f.path} already exists and wasn't made by the importer; move it out of the way first`);
          }
        }

        if (!dryRun) {
          const stageDir = path.join(staging, slug, dirName);
          fs.mkdirSync(stageDir, { recursive: true });
          got.forEach((g, i) => fs.writeFileSync(path.join(stageDir, path.basename(files[i].path)), g.buf));
          fs.mkdirSync(path.join(root, 'public', 'chapters', slug, dirName), { recursive: true });
          for (const f of files) {
            const staged = path.join(stageDir, path.basename(f.path));
            if (fileMatches(root, f)) fs.rmSync(staged);
            else fs.renameSync(staged, path.join(root, f.path));
          }
          const keep = new Set(files.map((f) => f.path));
          for (const old of prev?.files ?? []) {
            if (!keep.has(old.path) && insideChapters(old.path, slug)) fs.rmSync(path.join(root, old.path), { force: true });
          }
        }
        images = files.map((f) => `/${f.path.slice('public/'.length)}`);
      }

      const record = existing ?? newRecord(ref.number, info);
      const hadPages = (existing?.images ?? []).length;
      applyMeta(record, info, !existing);
      record.images = images;
      if (!existing) store.insertChapter(series.data, record);
      state.data.chapters[key] = { fingerprint, images, files, importedAt: now.toISOString() };
      save();

      anyPages = true;
      summary.imported++;
      summary.changed.push(record);
      note(ref.number, hadPages ? 'updated' : 'imported', { pages: images.length });
      log.info(hadPages ? 'chapter.updated' : 'chapter.imported', { chapter: ref.number, pages: images.length });
    } catch (e) {
      summary.failed++;
      note(ref.number, 'failed', { reason: e.message });
      log.error('chapter.failed', { chapter: ref.number, reason: e.message });
    }
  }

  // 4. Cover and credit, once the chapters are in.
  try {
    const cover = series.data.cover;
    const replaceCover = !cover || updateMetadata || (/^https?:/i.test(cover) && isBlockedHost(cover));
    if (anyPages && replaceCover && adapter.cover) {
      const found2 = await adapter.cover(ctx, found);
      if (found2 && mode === 'link' && found2.url && httpLink(found2.url)) {
        series.data.cover = found2.url;
        log.info('series.cover', { series: slug, cover: safeUrl(found2.url) });
      } else if (found2 && mode === 'copy') {
        const buf = found2.file ? fs.readFileSync(found2.file) : (await http.get(found2.url, { maxBytes, accept: 'image/*' })).body;
        const type = checkPage(buf, { maxBytes });
        const rel = `public/covers/${slug}.${type.ext}`;
        if (!dryRun) store.writeFileAtomic(path.join(root, rel), buf);
        series.data.cover = `/covers/${slug}.${type.ext}`;
        log.info('series.cover', { series: slug, cover: series.data.cover });
      }
    }
  } catch (e) {
    log.warn('series.cover-failed', { series: slug, reason: e.message });
  }
  if (anyPages && source.credit && JSON.stringify(series.data.source) !== JSON.stringify(source.credit)) {
    series.data.source = { name: source.credit.name, url: source.credit.url };
    log.info('series.credit', { series: slug, source: source.credit.name });
  }
  save();
  fs.rmSync(staging, { recursive: true, force: true });
  try {
    fs.rmdirSync(path.join(root, 'crawler', '.staging')); // only removed when empty
  } catch {}
  summary.seriesFile = path.relative(root, series.file);
  summary.newSeries = isNewSeries && series.data.chapters.length > 0;
  return summary;
}
