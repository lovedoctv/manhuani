// Checks page files by their content (not their name) and gives them safe, ordered names.
import crypto from 'node:crypto';

export const DEFAULT_MAX_PAGE_BYTES = 20 * 1024 * 1024;

/** Recognises JPEG, PNG, WebP, GIF and AVIF from the first bytes. SVG is refused on purpose: it can carry scripts. */
export function detectImage(buf) {
  if (!buf || buf.length < 12) return null;
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return { ext: 'jpg', mime: 'image/jpeg' };
  if (buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return { ext: 'png', mime: 'image/png' };
  const head6 = buf.toString('latin1', 0, 6);
  if (head6 === 'GIF87a' || head6 === 'GIF89a') return { ext: 'gif', mime: 'image/gif' };
  if (buf.toString('latin1', 0, 4) === 'RIFF' && buf.toString('latin1', 8, 12) === 'WEBP') return { ext: 'webp', mime: 'image/webp' };
  if (buf.toString('latin1', 4, 8) === 'ftyp' && /^avi[fs]$/.test(buf.toString('latin1', 8, 12))) return { ext: 'avif', mime: 'image/avif' };
  return null;
}

export const IMAGE_NAME = /\.(jpe?g|png|webp|gif|avif)$/i;

export const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');

/** 001, 002 … (more digits when a chapter has 1000+ pages), so files sort in reading order. */
export const pageFileName = (index, total, ext) => `${String(index + 1).padStart(Math.max(3, String(total).length), '0')}.${ext}`;

/** Sorts "2.jpg" before "10.jpg". */
export const naturalSort = (a, b) => a.localeCompare(b, 'en', { numeric: true, sensitivity: 'base' });

export function checkPage(buf, { maxBytes = DEFAULT_MAX_PAGE_BYTES } = {}) {
  if (!buf?.length) throw new Error('the file is empty');
  if (buf.length > maxBytes) throw new Error(`the file is ${buf.length} bytes, over the ${maxBytes} byte limit`);
  const type = detectImage(buf);
  if (!type) throw new Error("it isn't a JPEG, PNG, WebP, GIF or AVIF image");
  return type;
}
