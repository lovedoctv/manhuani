// Main site settings. Change these before going live.
export default {
  // Your live domain, with https:// and no trailing slash.
  // Used for canonical links, the sitemap, RSS and social previews.
  url: 'https://example.com',
  name: 'Manhuani',
  tagline: 'Read the latest manhua & manhwa updates',
  description:
    'Manhuani tracks the latest manhua and manhwa chapter updates, with series guides, reviews and reading lists for fans of action, romance, fantasy and cultivation stories.',
  language: 'en',
  // Phase 2: turn on once the crawler dashboard writes files to /data/live/.
  liveUpdates: false,
  // Number of series shown per page in "Latest Updates".
  perPage: 12,
  social: {
    discord: '',
    instagram: '',
    twitter: '',
  },
  // Genres shown in the menu. slug is used in the URL: /manga-genre/<slug>/
  genres: [
    { slug: 'action', name: 'Action' },
    { slug: 'romance', name: 'Romance' },
    { slug: 'fantasy', name: 'Fantasy' },
    { slug: 'martial-arts', name: 'Martial Arts' },
    { slug: 'adventure', name: 'Adventure' },
    { slug: 'ghost', name: 'Ghost' },
    { slug: 'cultivation', name: 'Cultivation' },
    { slug: 'system', name: 'System' },
  ],
};
