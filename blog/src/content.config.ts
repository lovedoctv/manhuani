import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';
import site from '../site.config.mjs';

const categories = site.categories.map((c) => c.slug) as [string, ...string[]];
const countries = site.countries.map((c) => c.slug) as [string, ...string[]];

// One Markdown file per post in src/content/posts/. See docs/BLOG.md for every field.
const posts = defineCollection({
  loader: glob({ pattern: '*.md', base: './src/content/posts' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    date: z.coerce.date(),
    updated: z.coerce.date().optional(),
    category: z.enum(categories),
    // Countries whose readers this post is for. Leave it out for posts that suit everyone.
    countries: z.array(z.enum(countries)).default([]),
    // When the facts were last checked against the official source.
    checked: z.coerce.date(),
    // The official page readers should apply on.
    official: z.object({ name: z.string(), url: z.string().url() }).optional(),
    // Application deadline. Posts with a future deadline show on /deadlines/ and the home page.
    deadline: z.coerce.date().optional(),
    // Short facts shown in a box at the top, e.g. { label: 'Level', value: "Master's" }.
    facts: z.array(z.object({ label: z.string(), value: z.string() })).default([]),
    featured: z.boolean().default(false),
    cover: z.string().optional(),
    draft: z.boolean().default(false),
  }),
});

export const collections = { posts };
