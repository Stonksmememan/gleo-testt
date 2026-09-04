/**
 * Whole-site GEO audit for an arbitrary URL.
 *
 * This is the public diagnostic path: crawl a site, score every page it finds,
 * and return itemized findings. It deliberately makes no LLM or search-API
 * calls, so it is cheap enough to run for a stranger who has installed
 * nothing. Tavily brand visibility and Gemini content generation stay on the
 * authenticated per-post path in lib/geo-analyzer.js.
 */

const cheerio = require('cheerio');

const { crawlSite, fetchText, readRobots } = require('./site-crawler');
const {
  analyzeContentSignals,
  calculateGeoScore,
  detectBuilderFromHtml,
  isHealthcarePractice,
} = require('./geo-scoring');
const {
  PILLARS,
  analyzeAiCrawlerAccess,
  buildPageFindings,
  aggregatePageFindings,
  buildSiteFindings,
  buildBlockers,
} = require('./findings');

/** Relative weight of each page type in the site score. */
const PAGE_TYPE_WEIGHTS = {
  homepage: 3,
  service: 2,
  provider: 1.5,
  about: 1.5,
  contact: 1.25,
  location: 1.25,
  page: 1,
};

const DENTAL_PATTERNS = /\b(dentist|dental|orthodont\w*|endodont\w*|periodont\w*|prosthodont\w*|oral surgeon|oral surgery|invisalign|teeth whitening|DDS|DMD)\b/i;
const MEDICAL_PATTERNS = /\b(physician|family medicine|internal medicine|pediatric\w*|urgent care|primary care|dermatolog\w*|cardiolog\w*|orthoped\w*|chiropract\w*|medical clinic|health clinic)\b/i;

/** Below this many visible words a page is treated as unread rather than empty. */
const MIN_READABLE_WORDS = 50;

const DENTAL_SCHEMA = /"@type"\s*:\s*"?\[?[^"\]]*\b(Dentist|DentistOffice)\b/i;
const MEDICAL_SCHEMA = /"@type"\s*:\s*"?\[?[^"\]]*\b(Physician|MedicalClinic|MedicalBusiness|Hospital|MedicalOrganization|PhysicianOffice)\b/i;

/**
 * High-signal, low-noise text from one page: title, meta description, and
 * headings. Body copy on script-heavy sites is drowned out by inline JSON
 * payloads, so these are counted separately and weighted more heavily.
 */
function extractIdentityText($) {
  const parts = [
    $('title').first().text(),
    $('meta[name="description"]').attr('content') || '',
    $('meta[property="og:site_name"]').attr('content') || '',
    $('meta[property="og:title"]').attr('content') || '',
    $('h1, h2').map((_, el) => $(el).text()).get().join(' '),
  ];
  return parts.join(' ').replace(/\s+/g, ' ').trim();
}

/**
 * Guess the practice type from crawled HTML so healthcare scoring weights can
 * be applied without the user filling in a profile first.
 *
 * Each page is parsed on its own: concatenating documents produces an invalid
 * DOM, and truncating the result silently discards the schema blocks and body
 * copy on sites that inline large script payloads.
 *
 * @returns {{practice_type: string, specialty: string, inferred: boolean, confidence: string, evidence: string}|null}
 */
function inferPracticeProfile(pages) {
  let dentalSchema = false;
  let medicalSchema = false;
  let dentalHits = 0;
  let medicalHits = 0;
  let dentalIdentityHits = 0;
  let medicalIdentityHits = 0;

  const countAll = (text, pattern) =>
    (text.match(new RegExp(pattern.source, 'gi')) || []).length;

  for (const page of pages) {
    if (!page.html) continue;
    const $ = cheerio.load(page.html);

    const schemaText = $('script[type="application/ld+json"]')
      .map((_, el) => $(el).text())
      .get()
      .join('\n');
    if (DENTAL_SCHEMA.test(schemaText)) dentalSchema = true;
    if (MEDICAL_SCHEMA.test(schemaText)) medicalSchema = true;

    const identityText = extractIdentityText($);
    dentalIdentityHits += countAll(identityText, DENTAL_PATTERNS);
    medicalIdentityHits += countAll(identityText, MEDICAL_PATTERNS);

    $('script, style, noscript').remove();
    const bodyText = ($('body').text() || '').replace(/\s+/g, ' ').slice(0, 60000);
    dentalHits += countAll(bodyText, DENTAL_PATTERNS);
    medicalHits += countAll(bodyText, MEDICAL_PATTERNS);
  }

  if (dentalSchema) {
    return { practice_type: 'dentist', specialty: 'dentistry', inferred: true, confidence: 'high', evidence: 'Dentist schema type in JSON-LD' };
  }
  if (medicalSchema) {
    return { practice_type: 'medical_clinic', specialty: '', inferred: true, confidence: 'high', evidence: 'Healthcare schema type in JSON-LD' };
  }

  // Titles and headings are worth more than body copy, which is noisier.
  const dentalScore = dentalIdentityHits * 3 + dentalHits;
  const medicalScore = medicalIdentityHits * 3 + medicalHits;

  if (dentalScore >= 3 && dentalScore >= medicalScore) {
    return {
      practice_type: 'dentist',
      specialty: 'dentistry',
      inferred: true,
      confidence: dentalIdentityHits > 0 ? 'high' : 'medium',
      evidence: `${dentalIdentityHits} dental term(s) in titles and headings, ${dentalHits} in body copy`,
    };
  }
  if (medicalScore >= 3) {
    return {
      practice_type: 'medical_clinic',
      specialty: '',
      inferred: true,
      confidence: medicalIdentityHits > 0 ? 'high' : 'medium',
      evidence: `${medicalIdentityHits} medical term(s) in titles and headings, ${medicalHits} in body copy`,
    };
  }

  return null;
}

/** Weighted mean of page scores, so the homepage counts more than a leaf page. */
function computeSiteScore(pageResults) {
  if (pageResults.length === 0) return 0;

  let weightedSum = 0;
  let weightTotal = 0;
  for (const page of pageResults) {
    const weight = PAGE_TYPE_WEIGHTS[page.type] ?? 1;
    weightedSum += page.score * weight;
    weightTotal += weight;
  }
  return weightTotal > 0 ? Math.round(weightedSum / weightTotal) : 0;
}

/** Points lost and available per pillar, for a summary bar in the UI. */
function summarizePillars(findings) {
  const byPillar = new Map();

  for (const key of Object.keys(PILLARS)) {
    byPillar.set(key, {
      pillar: key,
      label: PILLARS[key],
      points_available: 0,
      points_lost: 0,
      checks_failing: 0,
      checks_total: 0,
    });
  }

  for (const finding of findings) {
    const entry = byPillar.get(finding.pillar);
    if (!entry) continue;
    const multiplier = finding.scope === 'page' ? (finding.pages_total || 1) : 1;
    entry.points_available += finding.points * multiplier;
    entry.points_lost += finding.points_lost;
    entry.checks_total += 1;
    if (finding.status !== 'pass') entry.checks_failing += 1;
  }

  return [...byPillar.values()]
    .filter(p => p.checks_total > 0)
    .map(p => ({
      ...p,
      health: p.points_available > 0
        ? Math.round(((p.points_available - p.points_lost) / p.points_available) * 100)
        : 100,
    }));
}

/**
 * Audit a site end to end.
 *
 * @param {string} siteUrl              Any URL or bare hostname.
 * @param {object} [options]
 * @param {number} [options.maxPages=12] Pages to crawl.
 * @param {boolean} [options.allowRender=false] Use Playwright for JS-rendered pages.
 * @param {object} [options.practiceProfile] Supplied profile; skips inference.
 * @returns {Promise<object>} Audit report.
 */
async function auditSite(siteUrl, {
  maxPages = 12,
  allowRender = false,
  practiceProfile = null,
} = {}) {
  const startedAt = Date.now();

  const crawl = await crawlSite(siteUrl, { maxPages, allowRender });
  if (crawl.pages.length === 0) {
    throw new Error(`Crawled ${siteUrl} but could not read any pages`);
  }

  const origin = crawl.origin;

  // Site-level files are fetched directly rather than inferred from page HTML.
  const [llmsRes, robots] = await Promise.all([
    fetchText(new URL('/llms.txt', origin).toString(), { accept: 'text/plain,*/*' }),
    readRobots(origin),
  ]);

  const llmsTxtFound = llmsRes.ok && llmsRes.body.trim().length > 0
    && !/<html/i.test(llmsRes.body.slice(0, 400));

  const siteContext = {
    origin,
    llms_txt_found: llmsTxtFound,
    llms_txt_bytes: llmsTxtFound ? llmsRes.body.length : 0,
    robots_txt_found: robots.robotsFound,
    sitemap_url: crawl.discovery.sitemap_url,
    ai_crawlers: analyzeAiCrawlerAccess(robots.robotsBody, robots.robotsFound),
  };

  const profile = practiceProfile || inferPracticeProfile(crawl.pages);
  const healthcare = isHealthcarePractice(profile);

  const pageResults = [];
  const unreadable = [];

  for (const page of crawl.pages) {
    const signals = analyzeContentSignals(page.html, page.title, profile);

    // analyzeContentSignals infers this from a mention in the page HTML; the
    // crawler knows whether the file actually exists, which is the real signal.
    signals.has_llms_txt = llmsTxtFound;

    // A page we could not read is not a page that scores zero. Client-rendered
    // routes return markup with no text, and scoring them as empty would understate
    // the site. They are reported separately so the gap stays visible.
    if (signals.word_count < MIN_READABLE_WORDS) {
      unreadable.push({
        url: page.url,
        type: page.type,
        word_count: signals.word_count,
        html_bytes: (page.html || '').length,
        rendered: page.rendered,
        reason: page.rendered
          ? 'No text content found even after browser rendering'
          : 'No text content in the static HTML — the page is likely client-rendered',
      });
      continue;
    }

    const score = calculateGeoScore(signals, 0, [], profile);
    const findings = buildPageFindings(signals, { healthcare });

    pageResults.push({
      url: page.url,
      title: page.title,
      type: page.type,
      score,
      rendered: page.rendered,
      builder_detected: detectBuilderFromHtml(page.html || ''),
      word_count: signals.word_count,
      signals,
      findings,
      findings_failed: findings.filter(f => f.status === 'fail').length,
    });
  }

  if (pageResults.length === 0) {
    throw new Error(
      `Fetched ${crawl.pages.length} page(s) from ${origin} but none contained readable text. ` +
      'The site is likely client-rendered — retry with rendering enabled.'
    );
  }

  const aggregated = aggregatePageFindings(pageResults);
  const siteFindings = buildSiteFindings(siteContext);
  const allFindings = [...siteFindings, ...aggregated]
    .sort((a, b) => b.points_lost - a.points_lost || a.label.localeCompare(b.label));

  const blockers = buildBlockers(siteContext, pageResults);
  const siteScore = computeSiteScore(pageResults);

  if (unreadable.length > 0) {
    blockers.push({
      id: 'client_rendered_pages',
      severity: 'warning',
      label: `${unreadable.length} page(s) served no text without JavaScript`,
      detail: `${unreadable.map(p => p.url).join(', ')} returned markup but no readable content. Crawlers that do not execute JavaScript see the same emptiness. These pages are excluded from the score rather than counted as empty.`,
      fix_type: null,
      tier: null,
    });
  }

  return {
    site: {
      requested_url: crawl.discovery.requested_url,
      final_url: crawl.discovery.final_url,
      origin,
      host: new URL(origin).host,
    },
    score: {
      site_score: siteScore,
      weighting: healthcare ? 'healthcare' : 'standard',
      page_scores: pageResults.map(p => ({ url: p.url, type: p.type, score: p.score })),
      best_page: pageResults.reduce((a, b) => (b.score > a.score ? b : a)).url,
      worst_page: pageResults.reduce((a, b) => (b.score < a.score ? b : a)).url,
    },
    practice: profile
      ? { ...profile, healthcare_weighting: healthcare }
      : { practice_type: '', inferred: true, confidence: 'none', evidence: 'No healthcare signals found; standard weighting applied', healthcare_weighting: false },
    blockers,
    pillars: summarizePillars(allFindings),
    findings: allFindings,
    pages: pageResults.map(({ signals, findings, ...rest }) => rest),
    crawl: {
      pages_crawled: crawl.pages.length,
      pages_scored: pageResults.length,
      pages_failed: crawl.failed.length,
      failed: crawl.failed,
      unreadable,
      llms_txt_found: llmsTxtFound,
      robots_txt_found: robots.robotsFound,
      sitemap_url: crawl.discovery.sitemap_url,
      sitemap_page_count: crawl.discovery.sitemap_page_count,
      ai_crawlers_blocked: siteContext.ai_crawlers.blocked.map(c => c.ua),
      rendered_pages: pageResults.filter(p => p.rendered).length,
    },
    meta: {
      generated_at: new Date().toISOString(),
      duration_ms: Date.now() - startedAt,
      engine: 'geo-scoring/5-pillar',
    },
  };
}

module.exports = {
  auditSite,
  inferPracticeProfile,
  computeSiteScore,
  PAGE_TYPE_WEIGHTS,
};
