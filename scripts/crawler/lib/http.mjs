// HTTP for the importer: a timeout on every request, retries with backoff for errors that
// usually pass (timeouts, 429, 5xx), a minimum gap between requests to the same host, and
// a size limit so one bad response can't fill the disk.
import { isBlockedHost } from './sources.mjs';

const RETRY_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export class HttpError extends Error {
  constructor(message, { status = 0, retryable = false } = {}) {
    super(message);
    this.status = status;
    this.retryable = retryable;
  }
}

/** URL without its query string, so tokens in links never reach the logs. */
export const safeUrl = (url) => {
  try {
    const u = new URL(url);
    return `${u.origin}${u.pathname}`;
  } catch {
    return String(url);
  }
};

const parseRetryAfter = (value) => {
  if (!value) return 0;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return seconds * 1000;
  const date = Date.parse(value);
  return Number.isFinite(date) ? Math.max(0, date - Date.now()) : 0;
};

async function readLimited(res, maxBytes) {
  const declared = Number(res.headers.get('content-length'));
  if (Number.isFinite(declared) && declared > maxBytes) {
    await res.body?.cancel();
    throw new HttpError(`response is ${declared} bytes, over the ${maxBytes} byte limit`);
  }
  const chunks = [];
  let total = 0;
  for await (const chunk of res.body ?? []) {
    total += chunk.length;
    if (total > maxBytes) throw new HttpError(`response is over the ${maxBytes} byte limit`);
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

export function createHttp({
  timeoutMs = 20000,
  retries = 3,
  rateLimitMs = 1000,
  backoffMs = 500,
  headers = {},
  log,
  userAgent = 'ManhuaniImporter/1.0 (+https://aduna.fit/manhuani/)',
} = {}) {
  const lastRequest = new Map();
  let requests = 0;

  const waitTurn = async (host) => {
    const gap = (lastRequest.get(host) ?? 0) + rateLimitMs - Date.now();
    if (gap > 0) await sleep(gap);
    lastRequest.set(host, Date.now());
  };

  async function get(url, { maxBytes = 25 * 1024 * 1024, accept = '*/*' } = {}) {
    const target = new URL(url);
    if (!/^https?:$/.test(target.protocol)) throw new HttpError(`only http and https links can be fetched: ${safeUrl(url)}`);
    if (isBlockedHost(url)) throw new HttpError(`${target.hostname} is a site that re-hosts scans without permission, so it is never fetched`);
    for (let attempt = 0; ; attempt++) {
      await waitTurn(target.host);
      requests++;
      let failure;
      try {
        // Redirects are followed by hand so each new address is checked before it is requested.
        let current = url;
        let res;
        for (let hops = 0; ; hops++) {
          res = await fetch(current, {
            headers: { 'user-agent': userAgent, accept, ...headers },
            signal: AbortSignal.timeout(timeoutMs),
            redirect: 'manual',
          });
          const location = res.status >= 300 && res.status < 400 ? res.headers.get('location') : null;
          if (!location) break;
          await res.body?.cancel();
          if (hops >= 5) throw new HttpError(`too many redirects from ${safeUrl(url)}`);
          const next = new URL(location, current);
          if (!/^https?:$/.test(next.protocol)) throw new HttpError(`${safeUrl(url)} redirected to an unsupported address`);
          if (isBlockedHost(next.href)) throw new HttpError(`${safeUrl(url)} redirected to ${next.hostname}, a site that re-hosts scans without permission`);
          current = next.href;
        }
        if (res.ok) {
          const body = await readLimited(res, maxBytes);
          return { body, type: res.headers.get('content-type') ?? '', url: current };
        }
        await res.body?.cancel();
        failure = new HttpError(`HTTP ${res.status} from ${safeUrl(url)}`, { status: res.status, retryable: RETRY_STATUS.has(res.status) });
        failure.retryAfter = parseRetryAfter(res.headers.get('retry-after'));
      } catch (e) {
        if (e instanceof HttpError) throw e;
        const timedOut = e?.name === 'TimeoutError' || e?.name === 'AbortError';
        failure = new HttpError(timedOut ? `timed out after ${timeoutMs} ms: ${safeUrl(url)}` : `couldn't reach ${safeUrl(url)}: ${e?.cause?.code ?? e?.message ?? e}`, { retryable: true });
      }
      if (!failure.retryable || attempt >= retries) throw failure;
      const delay = Math.min(60000, Math.max(failure.retryAfter ?? 0, backoffMs * 2 ** attempt));
      log?.warn('http.retry', { url: safeUrl(url), reason: failure.message, attempt: attempt + 1, waitMs: delay });
      await sleep(delay);
    }
  }

  return {
    get,
    async json(url) {
      const { body, url: finalUrl } = await get(url, { maxBytes: 5 * 1024 * 1024, accept: 'application/json' });
      try {
        return { data: JSON.parse(body.toString('utf8')), url: finalUrl };
      } catch {
        throw new HttpError(`${safeUrl(url)} didn't return valid JSON`);
      }
    },
    get requestCount() {
      return requests;
    },
  };
}
