// Reads crawler/sources.json and checks each source before anything is fetched.
// A source can only supply pages when it has an authorization record: who gave permission,
// where the proof is kept, when, and what it allows.
import fs from 'node:fs';
import path from 'node:path';

// Sites that re-host other people's scans without permission. They have no rights to give,
// so the importer never fetches from them, whatever a source entry says.
export const BLOCKED_HOSTS = ['mangaread.org', 'comix.to', 'mangafire.to', 'manhuaus.com'];

export const isBlockedHost = (url) => {
  try {
    const host = new URL(url).hostname.toLowerCase();
    return BLOCKED_HOSTS.some((b) => host === b || host.endsWith(`.${b}`));
  } catch {
    return false;
  }
};

export const PERMISSIONS = {
  'publish-pages': 'copy the page files onto this site and show them',
  'link-pages': 'show the pages straight from the source’s own servers',
};

const SLUG = /^[a-z0-9][a-z0-9-]*$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

export const sourcesFile = (root) => path.join(root, 'crawler', 'sources.json');

export function loadSources(root) {
  const file = sourcesFile(root);
  if (!fs.existsSync(file)) return [];
  let data;
  try {
    data = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    throw new Error(`crawler/sources.json isn't valid JSON: ${e.message}`);
  }
  if (!Array.isArray(data.sources)) throw new Error('crawler/sources.json needs a "sources" list');
  return data.sources;
}

/** Every URL anywhere in a source entry, for the blocked-site check. */
const urlsIn = (value) =>
  typeof value === 'string'
    ? (/^https?:\/\//i.test(value) ? [value] : [])
    : value && typeof value === 'object'
      ? Object.values(value).flatMap(urlsIn)
      : [];

/** Returns a list of problems; an empty list means the source can be used. */
export function checkSource(source, adapters) {
  const problems = [];
  if (!source || typeof source !== 'object') return ['each source must be an object'];
  if (!SLUG.test(source.id ?? '')) problems.push('"id" must be lowercase letters, numbers and dashes');
  if (!source.name || typeof source.name !== 'string') problems.push('"name" is missing');
  const adapter = adapters[source.adapter];
  if (!adapter) problems.push(`"adapter" must be one of: ${Object.keys(adapters).join(', ')}`);

  const auth = source.authorization;
  if (!auth || typeof auth !== 'object') {
    problems.push('"authorization" is missing: say who gave permission, where the proof is kept, the date and what it allows');
  } else {
    if (!auth.grantedBy) problems.push('"authorization.grantedBy" is missing (the creator or publisher who gave permission)');
    if (!auth.evidence) problems.push('"authorization.evidence" is missing (where the written permission is kept)');
    if (!DATE.test(auth.date ?? '')) problems.push('"authorization.date" must look like 2026-10-20');
    const allows = Array.isArray(auth.allows) ? auth.allows : [];
    const unknown = allows.filter((a) => !PERMISSIONS[a]);
    if (!allows.length || unknown.length) problems.push(`"authorization.allows" must list one or more of: ${Object.keys(PERMISSIONS).join(', ')}`);
    const assets = source.assets ?? 'copy';
    if (assets !== 'copy' && assets !== 'link') problems.push('"assets" must be "copy" or "link"');
    else if (assets === 'copy' && !allows.includes('publish-pages')) problems.push('"assets": "copy" needs "publish-pages" in authorization.allows');
    else if (assets === 'link' && !allows.includes('link-pages')) problems.push('"assets": "link" needs "link-pages" in authorization.allows');
  }

  if (source.credit && (!source.credit.name || !/^https?:\/\//.test(source.credit.url ?? ''))) {
    problems.push('"credit" needs a "name" and a full "url"');
  }
  if (!source.series || typeof source.series !== 'object' || !Object.keys(source.series).length) {
    problems.push('"series" must list at least one series slug');
  } else {
    for (const slug of Object.keys(source.series)) if (!SLUG.test(slug)) problems.push(`series "${slug}" isn't a valid slug`);
  }
  const blocked = urlsIn(source).filter(isBlockedHost);
  if (blocked.length) problems.push(`${new URL(blocked[0]).hostname} re-hosts scans without permission and can't be a source`);
  if (adapter?.checkConfig) problems.push(...adapter.checkConfig(source));
  return problems;
}

/**
 * Turns header values like "env:PUBLISHER_TOKEN" into the secret's value. Secrets come from
 * environment variables, or from the CRAWLER_SECRETS variable holding a JSON object
 * (that's how the GitHub workflow passes them). They are never written to logs.
 */
export function resolveHeaders(headers = {}, env = process.env) {
  let bundle = {};
  try {
    bundle = env.CRAWLER_SECRETS ? JSON.parse(env.CRAWLER_SECRETS) : {};
  } catch {
    throw new Error('CRAWLER_SECRETS must be a JSON object like {"PUBLISHER_TOKEN": "..."}');
  }
  const out = {};
  for (const [name, value] of Object.entries(headers)) {
    if (typeof value === 'string' && value.startsWith('env:')) {
      const key = value.slice(4);
      const secret = env[key] ?? bundle[key];
      if (!secret) throw new Error(`header ${name} needs the secret ${key}, which isn't set`);
      out[name] = secret;
    } else {
      out[name] = String(value);
    }
  }
  return out;
}
