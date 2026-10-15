import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

// One JSON file per series in src/content/series/.
// The Phase 2 crawler will write files in exactly this shape.
const series = defineCollection({
  loader: glob({ pattern: '*.json', base: './src/content/series' }),
  schema: z.object({
    title: z.string(),
    altTitles: z.array(z.string()).default([]),
    type: z.enum(['manhua', 'manhwa', 'manga']),
    genres: z.array(z.string()),
    author: z.string().optional(),
    artist: z.string().optional(),
    status: z.enum(['ongoing', 'completed', 'hiatus', 'cancelled']),
    releaseYear: z.number().int(),
    rating: z.number().min(0).max(5).optional(),
    featured: z.boolean().default(false),
    cover: z.string().optional(),
    synopsis: z.string(),
    // Where the metadata came from (filled in by the crawler later).
    source: z
      .object({ name: z.string(), url: z.string().url() })
      .optional(),
    chapters: z.array(
      z.object({
        number: z.number(),
        title: z.string().optional(),
        date: z.coerce.date(),
        // Image URLs for chapters you are allowed to host.
        images: z.array(z.string()).default([]),
        // Link to the official/permitted source for this chapter.
        sourceUrl: z.string().url().optional(),
      }),
    ),
  }),
});

const blog = defineCollection({
  loader: glob({ pattern: '*.md', base: './src/content/blog' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    date: z.coerce.date(),
    updated: z.coerce.date().optional(),
    tags: z.array(z.string()).default([]),
    cover: z.string().optional(),
  }),
});

export const collections = { series, blog };
