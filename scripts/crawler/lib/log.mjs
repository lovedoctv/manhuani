// Structured logging for the importer.
// Every event goes to a JSON Lines file (one JSON object per line) and, unless quiet,
// as a short readable line to the terminal.
import fs from 'node:fs';
import path from 'node:path';

export function createLogger({ file, quiet = false, out = process.stdout } = {}) {
  let stream = null;
  if (file) {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    stream = fs.createWriteStream(file, { flags: 'a' });
  }
  const write = (level, event, data = {}) => {
    const record = { time: new Date().toISOString(), level, event, ...data };
    stream?.write(JSON.stringify(record) + '\n');
    if (!quiet) {
      const details = Object.entries(data)
        .map(([k, v]) => `${k}=${typeof v === 'string' ? v : JSON.stringify(v)}`)
        .join(' ');
      out.write(`[${level}] ${event}${details ? ' ' + details : ''}\n`);
    }
  };
  return {
    file,
    info: (event, data) => write('info', event, data),
    warn: (event, data) => write('warn', event, data),
    error: (event, data) => write('error', event, data),
    close: () => new Promise((resolve) => (stream ? stream.end(resolve) : resolve())),
  };
}

/** A logger that records nothing (used by tests). */
export const silentLogger = () => {
  const events = [];
  const push = (level) => (event, data = {}) => events.push({ level, event, ...data });
  return { events, info: push('info'), warn: push('warn'), error: push('error'), close: async () => {} };
};
