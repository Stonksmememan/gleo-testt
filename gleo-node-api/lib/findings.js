/**
 * Itemized GEO findings.
 *
 * generateRecommendations() in lib/geo-scoring.js produces one joined prose
 * string per pillar, which suits the per-post admin report card. A public
 * diagnostic needs discrete pass/fail items that can be counted, sorted,
 * linked to the fix that resolves them, and rolled up across pages.
 *
 * Point values mirror lib/geo-scoring.js so the findings add up to the score
 * rather than describing a separate rubric.
 */

const PILLARS = {
  technical: 'Technical & Crawlability',
  schema: 'Structured Data & Schema',
  content: 'Content Quality',
  credibility: 'Trust & Credibility',
  formatting: 'AI-Readable Formatting',
};

/**
 * User agents used by AI answer engines for crawling and retrieval.
 * `purpose` distinguishes crawlers that feed model training from those that
 * fetch pages to answer a live question — blocking the latter is what removes
 * a site from AI answers.
 */
const AI_CRAWLERS = [
  { ua: 'GPTBot', label: 'GPTBot (OpenAI)', purpose: 'training' },
  { ua: 'OAI-SearchBot', label: 'OAI-SearchBot (ChatGPT search)', purpose: 'retrieval' },
  { ua: 'ChatGPT-User', label: 'ChatGPT-User (live browsing)', purpose: 'retrieval' },
  { ua: 'ClaudeBot', label: 'ClaudeBot (Anthropic)', purpose: 'training' },
  { ua: 'Claude-User', label: 'Claude-User (live browsing)', purpose: 'retrieval' },
  { ua: 'PerplexityBot', label: 'PerplexityBot', purpose: 'retrieval' },
  { ua: 'Google-Extended', label: 'Google-Extended (Gemini grounding)', purpose: 'training' },
  { ua: 'Applebot-Extended', label: 'Applebot-Extended', purpose: 'training' },
  { ua: 'CCBot', label: 'CCBot (Common Crawl)', purpose: 'training' },
  { ua: 'meta-externalagent', label: 'meta-externalagent (Meta AI)', purpose: 'training' },
];

/**
 * Parse robots.txt into user-agent groups.
 * @returns {Array<{agents: string[], disallow: string[], allow: string[]}>}
 */
function parseRobots(body) {
  const groups = [];
  let current = null;
  let lastLineWasAgent = false;

  for (const rawLine of String(body || '').split(/\r?\n/)) {
    const line = rawLine.replace(/#.*$/, '').trim();
    if (!line) continue;

    const match = /^([a-z-]+)\s*:\s*(.*)$/i.exec(line);
    if (!match) continue;

    const field = match[1].toLowerCase();
    const value = match[2].trim();

    if (field === 'user-agent') {
      // Consecutive User-agent lines share one rule block.
      if (!current || !lastLineWasAgent) {
        current = { agents: [], disallow: [], allow: [] };
        groups.push(current);
      }
      current.agents.push(value.toLowerCase());
      lastLineWasAgent = true;
      continue;
    }

    lastLineWasAgent = false;
    if (!current) continue;
    if (field === 'disallow') current.disallow.push(value);
    else if (field === 'allow') current.allow.push(value);
  }

  return groups;
}

/**
 * Decide whether a path is disallowed for a user agent, using longest-match
 * precedence between Allow and Disallow as the robots.txt spec describes.
 * A group naming the agent explicitly wins over the wildcard group.
 */
function isPathDisallowed(groups, userAgent, path = '/') {
  const ua = userAgent.toLowerCase();
  const specific = groups.filter(g => g.agents.some(a => a !== '*' && ua.includes(a)));
  const wildcard = groups.filter(g => g.agents.includes('*'));
  const applicable = specific.length > 0 ? specific : wildcard;
  if (applicable.length === 0) return false;

  let bestDisallow = -1;
  let bestAllow = -1;
  for (const group of applicable) {
    for (const rule of group.disallow) {
      if (rule === '') continue; // "Disallow:" with no value permits everything
      if (path.startsWith(rule)) bestDisallow = Math.max(bestDisallow, rule.length);
    }
    for (const rule of group.allow) {
      if (rule === '') continue;
      if (path.startsWith(rule)) bestAllow = Math.max(bestAllow, rule.length);
    }
  }

  if (bestDisallow === -1) return false;
  return bestAllow < bestDisallow;
}

/**
 * Which AI crawlers are blocked from the site root.
 * @returns {{checked: boolean, blocked: Array<object>, allowed: Array<object>}}
 */
function analyzeAiCrawlerAccess(robotsBody, robotsFound) {
  if (!robotsFound) {
    // No robots.txt means nothing is disallowed.
    return { checked: true, blocked: [], allowed: AI_CRAWLERS.slice() };
  }
  const groups = parseRobots(robotsBody);
  const blocked = [];
  const allowed = [];
  for (const crawler of AI_CRAWLERS) {
    if (isPathDisallowed(groups, crawler.ua, '/')) blocked.push(crawler);
    else allowed.push(crawler);
  }
  return { checked: true, blocked, allowed };
}

/**
 * Page-scope checks. `points` is the score contribution at stake, matching
 * lib/geo-scoring.js. `fixType` names the plugin fix that resolves the item
 * and `tier` is its page-builder safety tier.
 */
function pageCheckDefinitions(healthcare) {
  return [
    // ── Technical ──
    {
      id: 'robots_meta_open', pillar: 'technical', label: 'Page is indexable',
      points: 5, fixType: null, tier: 'A',
      test: s => !s.has_meta_robots_block,
      fail: 'A robots meta tag sets noindex or nofollow, which tells crawlers to skip this page.',
      pass: 'No noindex or nofollow blocking crawlers.',
    },
    {
      id: 'alt_text', pillar: 'technical', label: 'Descriptive image alt text',
      points: 5, fixType: 'image_alt_text', tier: 'A',
      test: s => s.image_count === 0 || s.alt_text_coverage >= 90,
      fail: s => `${s.alt_text_coverage}% of ${s.image_count} images have descriptive alt text. Images without it are invisible to text-based retrieval.`,
      pass: 'Images carry descriptive alt text.',
    },

    // ── Schema ──
    {
      id: 'schema_present', pillar: 'schema', label: 'JSON-LD structured data',
      points: healthcare ? 8 : 10, fixType: 'schema', tier: 'A',
      test: s => s.has_schema,
      fail: 'No JSON-LD on the page, so AI engines have no machine-readable description of what this is.',
      pass: 'JSON-LD structured data present.',
    },
    {
      id: 'faq_schema', pillar: 'schema', label: 'FAQPage schema',
      points: healthcare ? 7 : 5, fixType: 'faq', tier: 'B',
      test: s => s.has_faq_schema,
      fail: healthcare
        ? 'No FAQPage schema. Patient question-and-answer content is the format AI engines most often surface for medical queries.'
        : 'No FAQPage schema, which is the structure that maps most directly onto how people ask AI questions.',
      pass: 'FAQPage schema present.',
    },
    {
      id: 'org_schema', pillar: 'schema', label: 'Organization or LocalBusiness schema',
      points: healthcare ? 4 : 5, fixType: 'schema_enrich', tier: 'A',
      test: s => s.has_org_schema,
      fail: 'No Organization or LocalBusiness schema, so the business itself is not described as an entity.',
      pass: 'Organization or LocalBusiness schema present.',
    },
    healthcare && {
      id: 'healthcare_schema', pillar: 'schema', label: 'Healthcare schema type',
      points: 4, fixType: 'schema', tier: 'A',
      test: s => s.has_healthcare_schema,
      fail: 'No Dentist, Physician, or MedicalClinic schema type, so AI cannot categorize this as a healthcare provider.',
      pass: 'Healthcare-specific schema type present.',
    },
    {
      id: 'nap', pillar: 'schema', label: 'Name, address, and phone',
      points: 3, fixType: 'schema_enrich', tier: 'A',
      test: s => s.has_nap_signals,
      fail: 'No address and phone number found together on the page — the core facts for local AI answers.',
      pass: 'Address and phone number present.',
    },
    {
      id: 'hours', pillar: 'schema', label: 'Opening hours',
      points: 2, fixType: 'schema_enrich', tier: 'A',
      test: s => s.has_hours_signals,
      fail: 'No opening hours found, so AI cannot answer whether you are open.',
      pass: 'Opening hours present.',
    },
    {
      id: 'booking', pillar: 'schema', label: 'Booking link',
      points: 2, fixType: null, tier: 'A',
      test: s => s.has_booking_link,
      fail: 'No booking or appointment link, so AI has no action to recommend after mentioning you.',
      pass: 'Booking link present.',
    },
    healthcare && {
      id: 'insurance', pillar: 'schema', label: 'Accepted insurance',
      points: 3, fixType: null, tier: 'B',
      test: s => s.has_insurance_signals,
      fail: 'No insurance information. "Do you take my insurance?" is one of the most common questions patients ask an assistant.',
      pass: 'Insurance information present.',
    },
    healthcare && {
      id: 'credentials', pillar: 'schema', label: 'Provider credentials',
      points: 3, fixType: null, tier: 'B',
      test: s => s.has_credentials_signals,
      fail: 'No provider credentials such as DDS, DMD, MD, or board certification.',
      pass: 'Provider credentials present.',
    },

    // ── Content ──
    {
      id: 'direct_answer', pillar: 'content', label: 'Direct opening answer',
      points: 5, fixType: 'opening_summary', tier: 'A',
      test: s => s.has_direct_answer,
      fail: s => `The opening paragraph is ${s.first_para_words} words. Retrieval favours a self-contained 40 to 120 word answer at the very top.`,
      pass: 'Opens with a direct, self-contained answer.',
    },
    {
      id: 'ai_summary', pillar: 'content', label: 'Machine-readable summary',
      points: 5, fixType: 'opening_summary', tier: 'A',
      test: s => s.has_tldr,
      fail: 'No summary block or AI overview metadata, so engines have to infer what the page covers.',
      pass: 'Summary available for crawlers.',
    },
    {
      id: 'word_count', pillar: 'content', label: 'Sufficient depth',
      points: healthcare ? 5 : 10, fixType: 'content_depth', tier: 'B',
      test: s => s.word_count >= (healthcare ? 800 : 2000),
      fail: s => healthcare
        ? `${s.word_count} words. Patient-facing pages do well from about 800 words once cost, insurance, and what-to-expect detail is included.`
        : `${s.word_count} words. Substantive pages generally need 2,000 or more to score full depth.`,
      pass: 'Page has enough depth to be worth citing.',
    },
    {
      id: 'conversational', pillar: 'content', label: 'Natural question phrasing',
      points: 3, fixType: 'readability', tier: 'C',
      test: s => s.has_conversational_queries,
      fail: 'The copy does not use the phrasing people actually type into an assistant.',
      pass: 'Uses natural, question-shaped language.',
    },
    healthcare && {
      id: 'local_intent', pillar: 'content', label: 'Local service area',
      points: 3, fixType: null, tier: 'B',
      test: s => s.has_local_intent_signals,
      fail: 'No city, neighbourhood, or service area named. Nearly all healthcare queries carry local intent.',
      pass: 'Service area named.',
    },

    // ── Credibility ──
    {
      id: 'citations', pillar: 'credibility', label: 'Outbound citations',
      points: 5, fixType: 'credibility', tier: 'B',
      test: s => s.citation_count >= 3,
      fail: s => `${s.citation_count} outbound link(s). Linking authoritative sources is one of the better-evidenced ways to raise citation rate.`,
      pass: 'Links to authoritative sources.',
    },
    {
      id: 'quotes', pillar: 'credibility', label: 'Quotes or testimonials',
      points: 5, fixType: 'expert_quotes', tier: 'B',
      test: s => s.has_quotes,
      fail: 'No quoted material. Quotations are among the most frequently extracted passages in generated answers.',
      pass: 'Contains quoted material.',
    },
    {
      id: 'statistics', pillar: 'credibility', label: 'Concrete figures',
      points: healthcare ? 3 : 5, fixType: 'authority', tier: 'B',
      test: s => s.stat_count >= 3,
      fail: s => healthcare
        ? `${s.stat_count} figure(s) found. Practice facts such as years in operation work here; invented clinical statistics do not.`
        : `${s.stat_count} figure(s) found. First-party numbers are strong citation bait.`,
      pass: 'Contains concrete figures.',
    },
    healthcare && {
      id: 'disclaimer', pillar: 'credibility', label: 'Medical disclaimer',
      points: 2, fixType: null, tier: 'B',
      test: s => s.has_disclaimer,
      fail: 'No disclaimer directing readers to their own provider. Assistants weight YMYL content partly on this.',
      pass: 'Disclaimer present.',
    },

    // ── Formatting ──
    {
      id: 'headings', pillar: 'formatting', label: 'Heading structure',
      points: 5, fixType: 'structure', tier: 'C',
      test: s => s.heading_count >= 4,
      fail: s => `${s.heading_count} heading(s). Headings are how retrieval systems chunk a page into citable passages.`,
      pass: 'Well-segmented with headings.',
    },
    {
      id: 'paragraph_length', pillar: 'formatting', label: 'Scannable paragraphs',
      points: 5, fixType: 'formatting', tier: 'C',
      test: s => s.long_paragraphs === 0 && s.paragraph_count > 0,
      fail: s => `${s.long_paragraphs} paragraph(s) run past 80 words, which produces chunks too diffuse to quote.`,
      pass: 'Paragraphs stay scannable.',
    },
    {
      id: 'lists', pillar: 'formatting', label: 'Lists',
      points: 4, fixType: 'formatting', tier: 'C',
      test: s => s.list_item_count >= 3,
      fail: 'Few or no lists. Enumerated content is disproportionately reused in generated answers.',
      pass: 'Uses lists.',
    },
    {
      id: 'faq_block', pillar: 'formatting', label: 'FAQ section',
      points: healthcare ? 8 : 6, fixType: 'faq', tier: 'B',
      test: s => s.has_faq,
      fail: 'No FAQ section. This is the single highest-value block to add for AI visibility.',
      pass: 'FAQ section present.',
    },
  ].filter(Boolean);
}

/**
 * Evaluate every page-scope check against one page's signals.
 * @returns {Array<object>} One finding per check, with status 'pass' or 'fail'.
 */
function buildPageFindings(signals, { healthcare = false } = {}) {
  return pageCheckDefinitions(healthcare).map((check) => {
    const passed = Boolean(check.test(signals));
    const detailSource = passed ? check.pass : check.fail;
    const detail = typeof detailSource === 'function' ? detailSource(signals) : detailSource;
    return {
      id: check.id,
      pillar: check.pillar,
      pillar_label: PILLARS[check.pillar],
      label: check.label,
      status: passed ? 'pass' : 'fail',
      points: check.points,
      points_lost: passed ? 0 : check.points,
      fix_type: check.fixType,
      tier: check.tier,
      detail,
    };
  });
}

/**
 * Roll page findings up to the site level.
 *
 * @param {Array<{url: string, findings: Array<object>}>} pageResults
 * @returns {Array<object>} One entry per check, sorted by total points lost.
 */
function aggregatePageFindings(pageResults) {
  const byId = new Map();

  for (const page of pageResults) {
    for (const finding of page.findings) {
      let entry = byId.get(finding.id);
      if (!entry) {
        entry = {
          id: finding.id,
          pillar: finding.pillar,
          pillar_label: finding.pillar_label,
          label: finding.label,
          scope: 'page',
          points: finding.points,
          fix_type: finding.fix_type,
          tier: finding.tier,
          pages_total: 0,
          pages_failing: 0,
          points_lost: 0,
          failing_pages: [],
          detail: finding.detail,
        };
        byId.set(finding.id, entry);
      }
      entry.pages_total += 1;
      if (finding.status === 'fail') {
        entry.pages_failing += 1;
        entry.points_lost += finding.points;
        entry.failing_pages.push({ url: page.url, detail: finding.detail });
        // Headline detail comes from the first failing page, so the example
        // shown is stable rather than whichever page happened to be last.
        if (entry.pages_failing === 1) entry.detail = finding.detail;
      }
    }
  }

  return [...byId.values()]
    .map(entry => ({
      ...entry,
      status: entry.pages_failing === 0 ? 'pass' : (entry.pages_failing === entry.pages_total ? 'fail' : 'partial'),
    }))
    .sort((a, b) => b.points_lost - a.points_lost || a.label.localeCompare(b.label));
}

/**
 * Site-scope findings that only make sense once, evaluated against files
 * fetched directly rather than inferred from page HTML.
 */
function buildSiteFindings(siteContext) {
  const findings = [];

  findings.push({
    id: 'llms_txt',
    pillar: 'technical',
    pillar_label: PILLARS.technical,
    label: '/llms.txt present',
    scope: 'site',
    points: 5,
    points_lost: siteContext.llms_txt_found ? 0 : 5,
    status: siteContext.llms_txt_found ? 'pass' : 'fail',
    fix_type: 'llms_txt',
    tier: 'A',
    detail: siteContext.llms_txt_found
      ? 'A /llms.txt file is being served.'
      : 'No /llms.txt. Adoption by major providers is still unconfirmed, so treat this as cheap insurance rather than a proven win.',
  });

  findings.push({
    id: 'sitemap',
    pillar: 'technical',
    pillar_label: PILLARS.technical,
    label: 'XML sitemap reachable',
    scope: 'site',
    points: 0,
    points_lost: 0,
    status: siteContext.sitemap_url ? 'pass' : 'fail',
    fix_type: null,
    tier: 'A',
    detail: siteContext.sitemap_url
      ? `Sitemap found at ${siteContext.sitemap_url}.`
      : 'No XML sitemap found, so crawlers have to discover pages by following links.',
  });

  return findings;
}

/**
 * Problems that make the rest of the audit moot. These are reported separately
 * from scored findings because they are binary and gate everything else.
 */
function buildBlockers(siteContext, pageResults) {
  const blockers = [];

  const retrievalBlocked = siteContext.ai_crawlers.blocked.filter(c => c.purpose === 'retrieval');
  const trainingBlocked = siteContext.ai_crawlers.blocked.filter(c => c.purpose === 'training');

  if (retrievalBlocked.length > 0) {
    blockers.push({
      id: 'ai_retrieval_blocked',
      severity: 'critical',
      label: 'robots.txt blocks AI answer engines',
      detail: `${retrievalBlocked.map(c => c.label).join(', ')} cannot fetch this site. These agents retrieve pages to answer live questions, so while this is in place no amount of on-page work will get the site cited.`,
      fix_type: 'robots_txt_allow',
      tier: 'A',
    });
  }

  if (trainingBlocked.length > 0) {
    blockers.push({
      id: 'ai_training_blocked',
      severity: 'warning',
      label: 'robots.txt blocks AI training crawlers',
      detail: `${trainingBlocked.map(c => c.label).join(', ')} are disallowed. This is a legitimate choice, but it reduces the chance of the brand being known to the underlying models.`,
      fix_type: 'robots_txt_allow',
      tier: 'A',
    });
  }

  const homepage = pageResults.find(p => p.type === 'homepage');
  if (homepage && homepage.signals.has_meta_robots_block) {
    blockers.push({
      id: 'homepage_noindex',
      severity: 'critical',
      label: 'Homepage is set to noindex',
      detail: 'The homepage carries a robots noindex or nofollow directive. This is usually left over from a staging setup and suppresses the whole site.',
      fix_type: null,
      tier: 'A',
    });
  }

  return blockers;
}

module.exports = {
  PILLARS,
  AI_CRAWLERS,
  parseRobots,
  isPathDisallowed,
  analyzeAiCrawlerAccess,
  buildPageFindings,
  aggregatePageFindings,
  buildSiteFindings,
  buildBlockers,
};
