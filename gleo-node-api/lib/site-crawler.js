/**
 * Live site crawler for arbitrary URLs.
 *
 * Replaces the WordPress-only page discovery in class-gleo-llms-scraper.php
 * (which enumerated pages through get_posts()) with sitemap plus homepage-nav
 * discovery, so a site can be audited before anything is installed on it.
 *
 * The classification, deprioritization, and scoring heuristics are ported from
 * that PHP class so page selection stays consistent between the two paths.
 */

const axios = require('axios');
const cheerio = require('cheerio');

const USER_AGENT = 'Mozilla/5.0 (compatible; GleoAudit/1.0; +https://gleo.ai/bot)';

/**
 * Some WAF configurations reject unrecognized bot user agents outright. We
 * identify honestly first and only fall back to a browser string when the
 * honest request is refused, since the alternative is failing to audit a site
 * whose owner asked us to look at it.
 */
const BROWSER_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

/** Statuses that indicate bot filtering rather than a genuinely missing page. */
const BOT_BLOCK_STATUSES = new Set([401, 403, 405, 406, 429, 503]);
const DEFAULT_MAX_PAGES = 12;
const FETCH_TIMEOUT_MS = 20000;
const MAX_HTML_BYTES = 4 * 1024 * 1024;
const MAX_NESTED_SITEMAPS = 4;
const DEFAULT_CONCURRENCY = 3;

const SERVICE_URL_PATTERNS = ['/services/', '/treatments/', '/procedures/', '/care/', '/solutions/'];

const ABOUT_SLUGS = [
  'about', 'about-us', 'team', 'our-team', 'meet-the-team', 'providers',
  'doctors', 'our-doctors', 'doctor', 'provider', 'staff', 'practice',
  'company', 'our-practice',
];

const CONTACT_SLUGS = ['contact', 'contact-us', 'locations', 'location', 'office', 'find-us'];

const DEPRIORITIZE_PATTERNS = [
  'privacy', 'terms', 'cookie', 'archive', 'search', '/page/', '/tag/',
  '/author/', '/category/', 'wp-login', 'cart', 'checkout', 'my-account',
  '/feed', 'wp-json', '/comments/', 'sitemap',
];

const SKIP_EXTENSIONS = /\.(pdf|jpe?g|png|gif|webp|svg|ico|css|js|json|xml|zip|gz|mp4|mp3|woff2?|ttf|eot|doc|docx|xls|xlsx)$/i;

const SITEMAP_CANDIDATES = [
  '/sitemap.xml',
  '/sitemap_index.xml',
  '/wp-sitemap.xml',
  '/sitemap-index.xml',
  '/sitemap1.xml',
];

/**
 * Coerce user input into a usable absolute URL.
 * @returns {string} Normalized origin-rooted URL.
 * @throws {Error} When the input cannot be parsed as an http(s) URL.
 */
function normalizeSiteUrl(input) {
  let raw = String(input || '').trim();
  if (!raw) throw new Error('No site URL provided');
  if (!/^https?:\/\//i.test(raw)) raw = `https://${raw}`;

  let parsed;
  try {
    parsed = new URL(raw);
  } catch (e) {
    throw new Error(`Not a valid URL: ${input}`);
  }
  if (!/^https?:$/.test(parsed.protocol)) {
    throw new Error(`Unsupported protocol: ${parsed.protocol}`);
  }
  parsed.hash = '';
  return parsed.toString();
}

/**
 * Stable dedupe key for a URL: origin plus path, ignoring query and hash.
 */
function urlKey(url) {
  try {
    const u = new URL(url);
    let path = u.pathname.replace(/\/+$/, '');
    if (path === '') path = '/';
    return `${u.protocol}//${u.host.toLowerCase()}${path}`;
  } catch (e) {
    return String(url);
  }
}

function sameOrigin(a, b) {
  try {
    const ua = new URL(a);
    const ub = new URL(b);
    return ua.host.replace(/^www\./i, '').toLowerCase() === ub.host.replace(/^www\./i, '').toLowerCase();
  } catch (e) {
    return false;
  }
}

/** Path depth, where "/" is 0 and "/a/b/" is 2. */
function pathDepth(url) {
  try {
    return new URL(url).pathname.split('/').filter(Boolean).length;
  } catch (e) {
    return 0;
  }
}

function slugFromUrl(url) {
  try {
    const segments = new URL(url).pathname.split('/').filter(Boolean);
    return (segments[segments.length - 1] || '').toLowerCase();
  } catch (e) {
    return '';
  }
}

async function fetchOnce(url, userAgent, timeout, accept) {
  try {
    const response = await axios.get(url, {
      timeout,
      maxRedirects: 5,
      maxContentLength: MAX_HTML_BYTES,
      responseType: 'text',
      headers: {
        'User-Agent': userAgent,
        Accept: accept,
        'Accept-Language': 'en-US,en;q=0.9',
        'Cache-Control': 'no-cache',
      },
      // Treat any status as resolvable so callers can distinguish 404 from a network error.
      validateStatus: () => true,
    });

    const body = typeof response.data === 'string' ? response.data : String(response.data ?? '');
    return {
      ok: response.status >= 200 && response.status < 300,
      status: response.status,
      body,
      finalUrl: response.request?.res?.responseUrl || url,
      error: null,
    };
  } catch (err) {
    return { ok: false, status: 0, body: '', finalUrl: url, error: err.message };
  }
}

/**
 * Fetch a URL as text. Never throws — failures are reported in the return value.
 * Retries once with a browser user agent when the first attempt looks bot-filtered.
 *
 * @returns {Promise<{ok: boolean, status: number, body: string, finalUrl: string, error: string|null, uaFallback: boolean}>}
 */
async function fetchText(url, {
  timeout = FETCH_TIMEOUT_MS,
  accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  allowUaFallback = true,
} = {}) {
  const first = await fetchOnce(url, USER_AGENT, timeout, accept);
  if (first.ok || !allowUaFallback || !BOT_BLOCK_STATUSES.has(first.status)) {
    return { ...first, uaFallback: false };
  }

  const retry = await fetchOnce(url, BROWSER_USER_AGENT, timeout, accept);
  if (retry.ok) return { ...retry, uaFallback: true };

  // Report the original refusal; the retry told us nothing new.
  return { ...first, uaFallback: false };
}

/**
 * Render a page with Playwright when the static HTML looks JS-driven.
 * Playwright is optional; a missing install degrades to the static fetch.
 * @returns {Promise<{html: string|null, error: string|null}>}
 */
async function renderPage(url) {
  let playwright;
  try {
    playwright = require('playwright');
  } catch (e) {
    return { html: null, error: 'Playwright is not installed. Run: npm install' };
  }

  let browser;
  try {
    browser = await playwright.chromium.launch({ headless: true });
    const page = await browser.newPage({
      viewport: { width: 1280, height: 900 },
      userAgent: USER_AGENT,
    });
    await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
    const html = await page.content();
    return { html, error: null };
  } catch (err) {
    const message = /Executable doesn't exist/i.test(err.message)
      ? 'Playwright browser is not installed. Run: npx playwright install chromium'
      : err.message.split('\n')[0];
    return { html: null, error: message };
  } finally {
    if (browser) await browser.close();
  }
}

/** Rough visible-word count, used to decide whether a page needs rendering. */
function visibleWordCount(html) {
  const $ = cheerio.load(html || '');
  $('script, style, noscript').remove();
  const text = ($('body').text() || '').replace(/\s+/g, ' ').trim();
  return text ? text.split(/\s+/).length : 0;
}

/**
 * Read Sitemap: directives out of robots.txt.
 * @returns {Promise<{robotsFound: boolean, robotsBody: string, sitemapUrls: string[]}>}
 */
async function readRobots(baseUrl) {
  const robotsUrl = new URL('/robots.txt', baseUrl).toString();
  const res = await fetchText(robotsUrl, { accept: 'text/plain,*/*' });
  if (!res.ok || !res.body) {
    return { robotsFound: false, robotsBody: '', sitemapUrls: [] };
  }
  const sitemapUrls = [];
  for (const line of res.body.split(/\r?\n/)) {
    const match = /^\s*sitemap\s*:\s*(\S+)/i.exec(line);
    if (match) {
      try {
        sitemapUrls.push(new URL(match[1], baseUrl).toString());
      } catch (e) {}
    }
  }
  return { robotsFound: true, robotsBody: res.body, sitemapUrls };
}

/**
 * Collect page URLs from a sitemap, recursing one level into sitemap indexes.
 * @returns {Promise<string[]>}
 */
async function readSitemap(sitemapUrl, depth = 0, seen = new Set()) {
  if (depth > 1 || seen.has(sitemapUrl)) return [];
  seen.add(sitemapUrl);

  const res = await fetchText(sitemapUrl, { accept: 'application/xml,text/xml,*/*' });
  if (!res.ok || !/</.test(res.body)) return [];

  const $ = cheerio.load(res.body, { xmlMode: true });
  const isIndex = $('sitemapindex').length > 0;
  const locs = $('loc').map((_, el) => $(el).text().trim()).get().filter(Boolean);

  if (!isIndex) return locs;

  const nested = locs.slice(0, MAX_NESTED_SITEMAPS);
  const collected = [];
  for (const nestedUrl of nested) {
    collected.push(...await readSitemap(nestedUrl, depth + 1, seen));
  }
  return collected;
}

/** Extract same-origin anchor hrefs and their link text from homepage HTML. */
function extractHomepageLinks(html, baseUrl) {
  const $ = cheerio.load(html || '');
  const links = [];
  const navLabels = [];

  $('nav a[href], header a[href], [class*="menu"] a[href]').each((_, el) => {
    const label = $(el).text().replace(/\s+/g, ' ').trim();
    if (label && label.length < 40) navLabels.push(label.toLowerCase());
  });

  $('a[href]').each((_, el) => {
    const href = $(el).attr('href') || '';
    if (!href || href.startsWith('#') || /^(mailto|tel|javascript):/i.test(href)) return;
    let abs;
    try {
      abs = new URL(href, baseUrl).toString();
    } catch (e) {
      return;
    }
    if (!sameOrigin(abs, baseUrl)) return;
    links.push({ url: abs, title: $(el).text().replace(/\s+/g, ' ').trim() });
  });

  return { links, navLabels: [...new Set(navLabels)] };
}

/** Ported from Gleo_Llms_Scraper::is_deprioritized(). */
function isDeprioritized(url) {
  const haystack = `${slugFromUrl(url)} ${url}`.toLowerCase();
  if (DEPRIORITIZE_PATTERNS.some(p => haystack.includes(p))) return true;
  if (/\/page\/\d+/i.test(url)) return true;
  if (SKIP_EXTENSIONS.test(new URL(url).pathname)) return true;
  return false;
}

/** Ported from Gleo_Llms_Scraper::classify_page(). */
function classifyPage(url, title = '') {
  const slug = slugFromUrl(url);
  const lowerUrl = url.toLowerCase();
  const haystack = `${slug} ${title} ${url}`.toLowerCase();

  if (SERVICE_URL_PATTERNS.some(p => lowerUrl.includes(p))) return 'service';
  if (/\b(services?|treatments?|procedures?|solutions?|care)\b/i.test(haystack)) return 'service';

  for (const needle of CONTACT_SLUGS) {
    if (slug === needle || slug.includes(needle)) {
      if (haystack.includes('location') || haystack.includes('office')) return 'location';
      return 'contact';
    }
  }

  for (const needle of ABOUT_SLUGS) {
    if (slug === needle || slug.includes(needle)) {
      if (/\b(team|doctor|provider|staff)\b/i.test(haystack)) return 'provider';
      return 'about';
    }
  }

  if (/\b(contact|location|office|hours|find us)\b/i.test(haystack)) return 'contact';

  return 'page';
}

/**
 * Ported from Gleo_Llms_Scraper::score_page(), adapted for pre-fetch selection:
 * word count is unavailable before fetching, so path depth substitutes.
 */
function scorePage(url, type, title, navLabels) {
  let score = 5;

  switch (type) {
    case 'homepage': score += 50; break;
    case 'service': score += 35; break;
    case 'about':
    case 'provider': score += 28; break;
    case 'contact':
    case 'location': score += 25; break;
    default: score += 3;
  }

  const slug = slugFromUrl(url);
  const lowerTitle = (title || '').toLowerCase();
  for (const label of navLabels) {
    if (!label) continue;
    if (lowerTitle.includes(label) || slug.includes(label.replace(/[^a-z0-9]+/g, '-'))) {
      score += 20;
      break;
    }
  }

  const depth = pathDepth(url);
  if (depth <= 1) score += 8;
  else if (depth === 2) score += 4;
  else score -= 4 * (depth - 2);

  return score;
}

/**
 * Discover and rank the pages worth auditing on a site.
 *
 * @param {string} siteUrl
 * @param {{maxPages?: number}} [options]
 * @returns {Promise<{origin: string, pages: Array<{url:string,title:string,type:string,score:number}>, discovery: object}>}
 */
async function discoverPages(siteUrl, { maxPages = DEFAULT_MAX_PAGES, allowRender = false } = {}) {
  const normalized = normalizeSiteUrl(siteUrl);

  let homepageRes = await fetchText(normalized);
  let homepageVia = homepageRes.uaFallback ? 'browser-ua' : 'static';
  let renderError = null;

  // A WAF that refuses every plain HTTP request may still serve a real browser.
  if (!homepageRes.ok && allowRender && BOT_BLOCK_STATUSES.has(homepageRes.status)) {
    const renderRes = await renderPage(normalized);
    if (renderRes.html) {
      homepageRes = { ok: true, status: 200, body: renderRes.html, finalUrl: normalized, error: null, uaFallback: false };
      homepageVia = 'rendered';
    } else {
      renderError = renderRes.error;
    }
  }

  if (!homepageRes.ok) {
    const reason = homepageRes.error || `HTTP ${homepageRes.status}`;
    let hint = '';
    if (BOT_BLOCK_STATUSES.has(homepageRes.status)) {
      if (renderError) hint = ` The site is behind a bot filter and browser rendering also failed: ${renderError}`;
      else if (!allowRender) hint = ' The site appears to be behind a bot filter — retry with rendering enabled.';
      else hint = ' The site is behind a bot filter that browser rendering did not clear.';
    }
    throw new Error(`Could not fetch ${normalized}: ${reason}.${hint}`);
  }

  // Redirects decide the real origin (http to https, www to apex, and so on).
  const origin = new URL(homepageRes.finalUrl).origin + '/';

  const { robotsFound, robotsBody, sitemapUrls } = await readRobots(origin);

  let sitemapPageUrls = [];
  let sitemapSource = null;
  const candidates = [...sitemapUrls, ...SITEMAP_CANDIDATES.map(p => new URL(p, origin).toString())];
  for (const candidate of candidates) {
    const found = await readSitemap(candidate);
    if (found.length > 0) {
      sitemapPageUrls = found;
      sitemapSource = candidate;
      break;
    }
  }

  const { links, navLabels } = extractHomepageLinks(homepageRes.body, origin);

  const scored = new Map();
  scored.set(urlKey(origin), {
    url: origin,
    title: '',
    type: 'homepage',
    score: 100,
  });

  const addCandidate = (rawUrl, title) => {
    if (!rawUrl) return;
    let abs;
    try {
      abs = new URL(rawUrl, origin).toString();
    } catch (e) {
      return;
    }
    if (!sameOrigin(abs, origin)) return;

    let deprioritized;
    try {
      deprioritized = isDeprioritized(abs);
    } catch (e) {
      return;
    }
    if (deprioritized) return;

    const key = urlKey(abs);
    if (key === urlKey(origin)) return;

    const type = classifyPage(abs, title);
    const score = scorePage(abs, type, title, navLabels);
    if (score < 1) return;

    const existing = scored.get(key);
    if (existing && existing.score >= score) {
      if (!existing.title && title) existing.title = title;
      return;
    }
    scored.set(key, { url: abs, title: title || '', type, score });
  };

  // Large sitemaps are capped: selection only needs enough candidates to rank.
  for (const url of sitemapPageUrls.slice(0, 500)) addCandidate(url, '');
  for (const link of links) addCandidate(link.url, link.title);

  const pages = [...scored.values()]
    .sort((a, b) => b.score - a.score)
    .slice(0, Math.max(1, maxPages));

  return {
    origin,
    pages,
    homepageHtml: homepageRes.body,
    discovery: {
      requested_url: normalized,
      final_url: homepageRes.finalUrl,
      homepage_fetched_via: homepageVia,
      robots_txt_found: robotsFound,
      robots_txt_body: robotsBody,
      sitemap_url: sitemapSource,
      sitemap_page_count: sitemapPageUrls.length,
      homepage_link_count: links.length,
      nav_labels: navLabels.slice(0, 20),
      candidates_considered: scored.size,
    },
  };
}

/** Run an async mapper over items with bounded concurrency, preserving order. */
async function mapWithConcurrency(items, limit, mapper) {
  const results = new Array(items.length);
  let cursor = 0;

  const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (cursor < items.length) {
      const index = cursor++;
      results[index] = await mapper(items[index], index);
    }
  });

  await Promise.all(workers);
  return results;
}

/**
 * Discover pages and fetch their HTML.
 *
 * @param {string} siteUrl
 * @param {{maxPages?: number, allowRender?: boolean, concurrency?: number}} [options]
 * @returns {Promise<{origin: string, discovery: object, pages: Array<object>}>}
 */
async function crawlSite(siteUrl, {
  maxPages = DEFAULT_MAX_PAGES,
  allowRender = false,
  concurrency = DEFAULT_CONCURRENCY,
} = {}) {
  const { origin, pages, discovery, homepageHtml } = await discoverPages(siteUrl, { maxPages, allowRender });

  const fetched = await mapWithConcurrency(pages, concurrency, async (page) => {
    // The homepage body is already in hand from discovery.
    let html = page.type === 'homepage' ? homepageHtml : null;
    let error = null;
    let status = html ? 200 : 0;

    if (!html) {
      const res = await fetchText(page.url);
      html = res.ok ? res.body : '';
      status = res.status;
      error = res.ok ? null : (res.error || `HTTP ${res.status}`);
    }

    let rendered = false;

    // Bot-filtered pages get one attempt through a real browser.
    if (!html && allowRender && BOT_BLOCK_STATUSES.has(status)) {
      const renderRes = await renderPage(page.url);
      if (renderRes.html) {
        html = renderRes.html;
        rendered = true;
        error = null;
      }
    }

    if (allowRender && !rendered && html && visibleWordCount(html) < 150) {
      const renderRes = await renderPage(page.url);
      if (renderRes.html) {
        html = renderRes.html;
        rendered = true;
      }
    }

    const $ = cheerio.load(html || '');
    const title = ($('title').first().text() || '').replace(/\s+/g, ' ').trim()
      || ($('h1').first().text() || '').replace(/\s+/g, ' ').trim()
      || page.title
      || page.url;

    return { ...page, title, html, status, error, rendered };
  });

  return {
    origin,
    discovery,
    pages: fetched.filter(p => p.html && p.html.length > 0),
    failed: fetched.filter(p => !p.html || p.html.length === 0).map(p => ({ url: p.url, error: p.error })),
  };
}

module.exports = {
  normalizeSiteUrl,
  fetchText,
  readRobots,
  readSitemap,
  discoverPages,
  crawlSite,
  classifyPage,
  visibleWordCount,
  USER_AGENT,
};
