// Main settings for the jobs abroad, study abroad and scholarships blog.
// Everything Ola is likely to change lives in this one file.
export default {
  // Your live domain, with https:// and no trailing slash.
  url: 'https://aduna.fit',
  // The folder the site lives in on that domain ('' for the domain root).
  // https://aduna.fit/AbroadPath/  ->  '/AbroadPath'
  // If you change it, also change DEPLOYPATH in .cpanel.yml and the address in blog/server/old-folder.htaccess.
  base: '/AbroadPath',
  name: 'AbroadPath',
  tagline: 'Scholarships, study abroad and work visas for Africans',
  description:
    'Fully funded scholarships, study abroad guides and work visa routes for Nigerians, South Africans, Kenyans, Ghanaians, Zimbabweans and Zambians, checked against official sources.',
  language: 'en',
  // Shown on the Contact page. Leave empty to hide it.
  contactEmail: '',
  // Posts per page on /blog/ and on category and country pages.
  perPage: 12,

  // Countries the site is written for. slug is used in the URL: /country/<slug>/
  countries: [
    { slug: 'nigeria', name: 'Nigeria', demonym: 'Nigerians', flag: '🇳🇬' },
    { slug: 'south-africa', name: 'South Africa', demonym: 'South Africans', flag: '🇿🇦' },
    { slug: 'kenya', name: 'Kenya', demonym: 'Kenyans', flag: '🇰🇪' },
    { slug: 'ghana', name: 'Ghana', demonym: 'Ghanaians', flag: '🇬🇭' },
    { slug: 'zimbabwe', name: 'Zimbabwe', demonym: 'Zimbabweans', flag: '🇿🇼' },
    { slug: 'zambia', name: 'Zambia', demonym: 'Zambians', flag: '🇿🇲' },
  ],

  // Categories in the menu. slug is used in the URL: /category/<slug>/
  categories: [
    { slug: 'scholarships', name: 'Scholarships', blurb: 'Fully and partly funded awards, with who can apply and when.' },
    { slug: 'study-abroad', name: 'Study Abroad', blurb: 'Admissions, study permits, costs and student visas.' },
    { slug: 'work-visas', name: 'Work Visas', blurb: 'Work visa routes that are open now, and what each one needs.' },
    { slug: 'jobs-abroad', name: 'Jobs Abroad', blurb: 'Sectors hiring from abroad and how to apply safely.' },
    { slug: 'exams', name: 'IELTS & Exams', blurb: 'IELTS, TOEFL, Duolingo and other tests: fees, dates and prep.' },
    { slug: 'guides', name: 'Guides', blurb: 'CVs, statements of purpose, proof of funds and interviews.' },
  ],

  // Google AdSense publisher ID, like 'ca-pub-1234567890123456' (AdSense > Account > Account information).
  // When it's set, every page gets the AdSense code (for Auto ads, and so Google can verify the site),
  // the privacy page explains Google's ad cookies, and each deploy writes https://aduna.fit/ads.txt.
  adsense: 'ca-pub-7485485603555101',
  // Extra lines for https://aduna.fit/ads.txt, if another ad network asks for them, one string per line.
  adsTxt: [],
  // true hides every code in ads: below (Monetag, Adsterra) without deleting it, for example while Google
  // reviews the site for AdSense. AdSense itself isn't affected. Set it back to false to show them again.
  adsPaused: true,

  // Ad codes from Adsterra or Monetag. Paste each code exactly as the dashboard gives it,
  // between the backticks. An empty slot shows nothing, so the site works before you add any.
  ads: {
    // Goes inside <head> on every page: site verification tags, or a Monetag tag that asks for <head>.
    head: ``,
    // Goes at the end of <body> on every page: Adsterra Social Bar, Monetag In-Page Push or Vignette.
    bodyEnd: `<!-- Monetag In-Page Push, zone 12100047 -->
<script>(function(s){s.dataset.zone='12100047',s.src='https://ueuee.com/tag.min.js'})([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>
<!-- Monetag Vignette Banner, zone 12100049 -->
<script>(function(s){s.dataset.zone='12100049',s.src='https://dd133.com/vignette.min.js'})([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>
<!-- Monetag Push Notifications, zone 12100055 (uses the sw.js at https://aduna.fit/sw.js) -->
<script src="https://5gvci.com/act/files/tag.min.js?z=12100055" data-cfasync="false" async></script>`,
    // A banner under the menu on every page.
    belowHeader: ``,
    // Inside each post, after the 2nd paragraph (a native or 300x250 banner works well).
    inArticle: ``,
    // Inside each post, about two thirds of the way down.
    inArticle2: ``,
    // At the end of each post, before related posts.
    afterArticle: ``,
    // Between sections on the home page.
    home: ``,
  },

  // Visitor counter (Supercounters "users online" badge), shown at the bottom of every page for every
  // visitor, TikTok visitors included. Paste the code exactly as Supercounters gives it. Empty shows nothing.
  counter: `<!-- BEGIN: Powered by Supercounters.com -->
<center><script type="text/javascript" src="//widget.supercounters.com/ssl/online_i.js"></script><script type="text/javascript">sc_online_i(1739775,"f5f5f5","fafafa");</script></center>
<!-- END: Powered by Supercounters.com -->`,

  // Visitors who arrive from an ad with one of these utm_source values don't get the bodyEnd code
  // (Social Bar, In-Page Push, Vignette). Empty means every visitor gets the bodyEnd code. Since
  // 2026-10-22 TikTok visitors get the same Monetag ads as Facebook visitors, by choice. To give
  // utm_source=tiktok visitors (and TikTok's reviewers) the page without them again, set ['tiktok'].
  quietSources: [],

  // Pixel IDs (just the number), so Meta and TikTok can optimise for landing page views and offer clicks.
  pixels: {
    meta: '',
    tiktok: '',
    // Google Analytics 4 Measurement ID, like G-ABC123XYZ (Admin > Data streams > your web stream).
    google: 'G-BHCY6F4J33',
  },

  // CPA and affiliate offers. Each one shows as a "Recommended" box on posts in its categories.
  // In the link you can use {source}, {campaign} and {post}: they're filled in from the visitor's
  // utm_source and utm_campaign and the post's slug, so your network reports which ad and post
  // sent the conversion. An offer with an empty url is skipped.
  // Example:
  // { id: 'ielts-prep', title: 'Prepare for IELTS online', text: 'Practice tests and lessons...',
  //   cta: 'Start practising', url: 'https://network.example/click?o=123&sub1={source}&sub2={campaign}&sub3={post}',
  //   categories: ['exams', 'study-abroad'] },
  offers: [],

  social: {
    facebook: '',
    tiktok: '',
    instagram: '',
    whatsappChannel: '',
    telegram: '',
  },
};
