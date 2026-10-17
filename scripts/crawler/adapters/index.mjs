// The adapters the importer can use. Each one knows how to read one kind of permitted source.
//
// An adapter is an object with:
//   name, description
//   checkConfig(source) -> string[]             problems with the source entry (empty when fine)
//   discover(ctx) -> { series?, chapters }      the series details and its chapter list
//       series:   { title, type, status, genres, author, artist, releaseYear, synopsis, altTitles } (all optional)
//       chapters: [{ number, title?, date?, sourceUrl?, ...anything fetchChapter needs }]
//   fetchChapter(ctx, chapter) -> { pages, title?, date?, sourceUrl?, version? }
//       pages: in reading order, each { file } (a local path) or { url }
//       version: optional text that changes whenever the chapter's pages change
//   cover?(ctx, discovered) -> { file } | { url } | null   an optional cover image
//
// ctx is { root, source, slug, config, http, log }, where config is source.series[slug].
// To add a source type, write a new file next to this one and add it below.
import folder from './folder.mjs';
import feed from './feed.mjs';

export const adapters = { folder, feed };
