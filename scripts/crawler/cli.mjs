#!/usr/bin/env node
// Imports chapters and pages from sources you have permission to publish.
// Run `npm run crawler -- --help` for the options, and see docs/IMPORTER.md.
import fs from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { adapters } from './adapters/index.mjs';
import { createHttp } from './lib/http.mjs';
import { importSeries } from './lib/importer.mjs';
import { createLogger } from './lib/log.mjs';
import { BLOCKED_HOSTS, checkSource, loadSources, resolveHeaders } from './lib/sources.mjs';

const HELP = `Import chapters from a source you have permission to publish.

  npm run crawler -- --source <source id> --series <series slug> [options]
  npm run crawler -- --all [options]       every series of every source
  npm run crawler -- --list                show the sources in crawler/sources.json

Options:
  --dry-run            fetch and check everything, but change no files
  --preview            like --dry-run, and print the chapter records that would change
  --chapters <list>    only these chapters, e.g. 1-5,8,10.5
  --force              fetch pages again even when nothing changed
  --update-metadata    let the source replace chapter titles, dates and links you already have
  --json               print the summary as JSON
  --summary-md <file>  also write the summary as Markdown (used by the GitHub workflow)
  --root <folder>      the project folder (default: this repository)
  --quiet              only print the summary

Exit code: 0 when everything worked, 2 when some chapters failed, 1 when nothing could run.`;

const parseChapters = (text) => {
  const parts = text.split(',').map((s) => s.trim()).filter(Boolean).map((part) => {
    const m = part.match(/^(\d+(?:\.\d+)?)(?:-(\d+(?:\.\d+)?))?$/);
    if (!m) throw new Error(`--chapters: "${part}" isn't a chapter number or range like 1-5`);
    return [Number(m[1]), Number(m[2] ?? m[1])];
  });
  return (n) => parts.some(([a, b]) => n >= a && n <= b);
};

function printSummary(s) {
  const lines = [];
  lines.push(`\n${s.dryRun ? 'Dry run (nothing was changed)' : 'Import'}: ${s.series} from ${s.source}`);
  if (s.fatal) {
    lines.push(`  Not imported: ${s.fatal}`);
    return lines.join('\n');
  }
  lines.push(`  Discovered ${s.discovered} · ${s.dryRun ? 'would import or update' : 'imported or updated'} ${s.imported} · unchanged ${s.unchanged} · no pages ${s.noPages} · skipped ${s.skipped} · failed ${s.failed}`);
  for (const c of s.chapters.filter((c) => c.status === 'failed' || c.status === 'skipped')) {
    lines.push(`  Chapter ${c.number ?? '?'}: ${c.status} (${c.reason})`);
  }
  if (!s.dryRun && (s.imported || s.newSeries)) lines.push(`  Saved to ${s.seriesFile}${s.mode === 'copy' ? ` and public/chapters/${s.series}/` : ''}`);
  return lines.join('\n');
}

const markdown = (results) => [
  '| Series | Source | Discovered | Imported | Unchanged | No pages | Skipped | Failed |',
  '|---|---|---|---|---|---|---|---|',
  ...results.map((s) => (s.fatal
    ? `| ${s.series} | ${s.source} | – | – | – | – | – | ${s.fatal.replace(/\|/g, '/')} |`
    : `| ${s.series} | ${s.source} | ${s.discovered} | ${s.imported} | ${s.unchanged} | ${s.noPages} | ${s.skipped} | ${s.failed} |`)),
  ...results.flatMap((s) => (s.chapters ?? []).filter((c) => c.status === 'failed').map((c) => `\n- ${s.series} chapter ${c.number}: ${c.reason}`)),
].join('\n');

async function main(argv) {
  let args;
  try {
    ({ values: args } = parseArgs({
      args: argv,
      options: {
        source: { type: 'string' }, series: { type: 'string' }, all: { type: 'boolean' }, list: { type: 'boolean' },
        'dry-run': { type: 'boolean' }, preview: { type: 'boolean' }, chapters: { type: 'string' }, force: { type: 'boolean' },
        'update-metadata': { type: 'boolean' }, json: { type: 'boolean' }, 'summary-md': { type: 'string' },
        root: { type: 'string' }, quiet: { type: 'boolean' }, help: { type: 'boolean', short: 'h' },
      },
    }));
  } catch (e) {
    console.error(`${e.message}\n\n${HELP}`);
    return 1;
  }
  if (args.help) {
    console.log(HELP);
    return 0;
  }
  const root = path.resolve(args.root ?? path.join(import.meta.dirname, '..', '..'));
  let sources;
  try {
    sources = loadSources(root);
  } catch (e) {
    console.error(e.message);
    return 1;
  }

  if (args.list) {
    if (!sources.length) console.log('No sources yet. Add one to crawler/sources.json (see docs/IMPORTER.md).');
    for (const s of sources) {
      const problems = checkSource(s, adapters);
      console.log(`${s.id} (${s.adapter}) ${s.name ?? ''}`);
      console.log(`  series: ${Object.keys(s.series ?? {}).join(', ') || 'none'}`);
      console.log(`  permission: ${s.authorization?.grantedBy ?? 'none'}${s.authorization?.allows ? ` · allows ${s.authorization.allows.join(', ')}` : ''}`);
      console.log(problems.length ? `  can't be used: ${problems.join('; ')}` : '  ready');
    }
    return 0;
  }

  const jobs = [];
  if (args.all) {
    for (const s of sources) for (const slug of Object.keys(s.series ?? {})) jobs.push([s, slug]);
    if (!jobs.length) {
      console.log('No sources yet, so there is nothing to import. Add one to crawler/sources.json (see docs/IMPORTER.md).');
      if (args['summary-md']) fs.writeFileSync(args['summary-md'], 'No sources are set up yet, so nothing was imported.\n');
      return 0;
    }
  } else {
    if (!args.source || !args.series) {
      console.error(`Give --source and --series, or --all.\n\n${HELP}`);
      return 1;
    }
    const source = sources.find((s) => s.id === args.source);
    if (!source) {
      const blocked = BLOCKED_HOSTS.find((h) => h.split('.')[0] === args.source.toLowerCase());
      console.error(blocked
        ? `${blocked} re-hosts scans without permission, so it can't be a source. Pages need permission from the creator or publisher.`
        : `There's no source "${args.source}" in crawler/sources.json. Sources: ${sources.map((s) => s.id).join(', ') || 'none yet'}.`);
      return 1;
    }
    if (!source.series?.[args.series]) {
      console.error(`Source "${source.id}" has no series "${args.series}". Its series: ${Object.keys(source.series ?? {}).join(', ') || 'none'}.`);
      return 1;
    }
    jobs.push([source, args.series]);
  }

  let chapterFilter = null;
  try {
    if (args.chapters) chapterFilter = parseChapters(args.chapters);
  } catch (e) {
    console.error(e.message);
    return 1;
  }

  const dryRun = Boolean(args['dry-run'] || args.preview);
  const runId = new Date().toISOString().replace(/[:.]/g, '-');
  const results = [];
  for (const [source, slug] of jobs) {
    const problems = checkSource(source, adapters);
    if (problems.length) {
      results.push({ source: source.id ?? '?', series: slug, dryRun, fatal: `the source can't be used: ${problems.join('; ')}` });
      continue;
    }
    const log = createLogger({ file: path.join(root, 'crawler', 'logs', `${runId}-${source.id}-${slug}.jsonl`), quiet: args.quiet || args.json });
    let summary;
    try {
      const http = createHttp({
        timeoutMs: source.timeoutMs, retries: source.retries, rateLimitMs: source.rateLimitMs,
        headers: resolveHeaders(source.headers), log,
      });
      log.info('run.start', { source: source.id, series: slug, dryRun, assets: source.assets ?? 'copy', permission: source.authorization.grantedBy });
      summary = await importSeries({
        root, source, slug, adapter: adapters[source.adapter], http, log, runId, dryRun,
        force: args.force, updateMetadata: args['update-metadata'], chapterFilter,
      });
      log.info('run.end', { ...summary, chapters: undefined, changed: undefined, requests: http.requestCount });
    } catch (e) {
      summary = { source: source.id, series: slug, dryRun, fatal: e.message };
      log.error('run.failed', { reason: e.message });
    }
    summary.log = path.relative(root, log.file);
    await log.close();
    results.push(summary);
    if (args.preview && summary.changed?.length) {
      console.log(`\nChapter records that would change in ${summary.seriesFile}:`);
      console.log(JSON.stringify(summary.changed, null, 2));
    }
  }

  if (args.json) console.log(JSON.stringify(results.map(({ changed, ...r }) => r), null, 2));
  else for (const r of results) console.log(printSummary(r) + (r.log ? `\n  Log: ${r.log}` : ''));
  if (args['summary-md']) fs.writeFileSync(args['summary-md'], markdown(results) + '\n');

  if (results.every((r) => r.fatal)) return 1;
  return results.some((r) => r.fatal || r.failed) ? 2 : 0;
}

export { main };

if (import.meta.url === `file://${process.argv[1]}` || process.argv[1]?.endsWith('cli.mjs')) {
  main(process.argv.slice(2)).then((code) => process.exit(code));
}
