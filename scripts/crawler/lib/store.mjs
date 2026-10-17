// Reading and writing the site's own files: series JSON in src/content/series/, page files in
// public/chapters/, and the importer's record of what it imported in crawler/state/.
// Every write goes to a temporary file first and is then renamed into place, so an
// interrupted run never leaves a half-written file.
import fs from 'node:fs';
import path from 'node:path';

export const seriesFile = (root, slug) => path.join(root, 'src', 'content', 'series', `${slug}.json`);
export const stateFile = (root, sourceId, slug) => path.join(root, 'crawler', 'state', sourceId, `${slug}.json`);

/** Same folder name the site uses for chapter URLs: 10.5 becomes "10-5". */
export const chapterDir = (n) => String(n).replace('.', '-');
export const chapterKey = (n) => String(Number(n));

export function writeFileAtomic(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const tmp = path.join(path.dirname(file), `.${path.basename(file)}.${process.pid}.tmp`);
  fs.writeFileSync(tmp, data);
  fs.renameSync(tmp, file);
}

export function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/** Loads a series file and remembers its indentation, so saving it keeps the same layout. */
export function loadSeries(root, slug) {
  const file = seriesFile(root, slug);
  if (!fs.existsSync(file)) return { file, data: null, indent: 2 };
  const text = fs.readFileSync(file, 'utf8');
  const indent = text.match(/\n( +)"/)?.[1].length ?? 2;
  try {
    return { file, data: JSON.parse(text), indent };
  } catch (e) {
    throw new Error(`${path.relative(root, file)} isn't valid JSON: ${e.message}`);
  }
}

/** Writes only when something changed, so a rerun with nothing new leaves files untouched. */
const writeIfChanged = (file, text) => {
  if (fs.existsSync(file) && fs.readFileSync(file, 'utf8') === text) return false;
  writeFileAtomic(file, text);
  return true;
};

export function saveSeries(store) {
  return writeIfChanged(store.file, JSON.stringify(store.data, null, store.indent) + '\n');
}

export function loadState(root, sourceId, slug) {
  const file = stateFile(root, sourceId, slug);
  const empty = { source: sourceId, series: slug, chapters: {} };
  if (!fs.existsSync(file)) return { file, data: empty };
  try {
    return { file, data: { ...empty, ...readJson(file) } };
  } catch {
    return { file, data: empty };
  }
}

export function saveState(state) {
  const { updated, ...rest } = state.data;
  const onDisk = fs.existsSync(state.file) ? (() => {
    try {
      const { updated: _, ...saved } = readJson(state.file);
      return JSON.stringify(saved);
    } catch {
      return null;
    }
  })() : null;
  if (onDisk === JSON.stringify(rest)) return false;
  state.data = { ...rest, updated: new Date().toISOString() };
  writeFileAtomic(state.file, JSON.stringify(state.data, null, 2) + '\n');
  return true;
}

/** Finds a chapter record by number. */
export const findChapter = (series, n) => series.chapters.find((c) => Number(c.number) === Number(n));

/** Adds a chapter record, keeping the file's existing order (newest first or oldest first). */
export function insertChapter(series, record) {
  const list = series.chapters;
  const descending = list.length > 1 && Number(list[0].number) > Number(list[list.length - 1].number);
  let i = list.findIndex((c) => (descending ? Number(c.number) < record.number : Number(c.number) > record.number));
  if (i === -1) i = list.length;
  list.splice(i, 0, record);
}

const SERIES_TYPES = ['manhua', 'manhwa', 'manga'];
const SERIES_STATUS = ['ongoing', 'completed', 'hiatus', 'cancelled'];

/** A new series record in the shape src/content.config.ts expects. */
export function newSeries(meta, now) {
  const problems = [];
  if (!meta?.title) problems.push('the source gives no series title');
  const type = meta?.type ?? 'manhwa';
  const status = meta?.status ?? 'ongoing';
  if (!SERIES_TYPES.includes(type)) problems.push(`series type must be one of ${SERIES_TYPES.join(', ')}`);
  if (!SERIES_STATUS.includes(status)) problems.push(`series status must be one of ${SERIES_STATUS.join(', ')}`);
  if (problems.length) throw new Error(`can't create the series: ${problems.join('; ')}`);
  const data = {
    title: String(meta.title),
    altTitles: Array.isArray(meta.altTitles) ? meta.altTitles.map(String) : [],
    type,
    genres: Array.isArray(meta.genres) ? meta.genres.map(String) : [],
    ...(meta.author ? { author: String(meta.author) } : {}),
    ...(meta.artist ? { artist: String(meta.artist) } : {}),
    status,
    releaseYear: Number.isInteger(meta.releaseYear) ? meta.releaseYear : now.getFullYear(),
    synopsis: String(meta.synopsis ?? ''),
    chapters: [],
  };
  return data;
}
