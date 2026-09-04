/**
 * Pure GEO scoring primitives.
 *
 * Everything here operates on an HTML string and plain objects. There are no
 * network calls, no LLM clients, and no WordPress assumptions, so these
 * functions can score any URL on the internet — see lib/site-audit.js.
 *
 * lib/geo-analyzer.js layers Tavily and Gemini on top of these for the
 * authenticated per-post scan path.
 */

const cheerio = require('cheerio');

/**
 * Returns true when the practice profile indicates a healthcare practice
 * (dentist, physician, or medical_clinic). All other practice types, and
 * sites with no profile, use the standard scoring weights.
 */
function isHealthcarePractice(practiceProfile) {
  const type = practiceProfile?.practice_type || '';
  return ['dentist', 'physician', 'medical_clinic'].includes(type.toLowerCase());
}

/**
 * Extracts existing FAQ question/answer pairs from live HTML using multiple sources.
 * Priority order:
 *   1. FAQPage JSON-LD mainEntity nodes
 *   2. <details><summary> blocks
 *   3. Accordion markup: aria-expanded button + sibling answer element
 *   4. H3/H4 heading ending in "?" inside FAQ-labelled sections
 *
 * Returns up to 6 deduplicated {q, a} pairs, or an empty array.
 */
function extractExistingFaqs(htmlContent) {
  const $ = cheerio.load(htmlContent || '');
  const pairs = [];
  const seenQ = new Set();

  const addPair = (q, a) => {
    const qNorm = (q || '').trim().replace(/\s+/g, ' ');
    const aNorm = (a || '').trim().replace(/\s+/g, ' ');
    if (!qNorm || !aNorm || qNorm.length < 5 || aNorm.length < 5) return;
    if (seenQ.has(qNorm.toLowerCase())) return;
    seenQ.add(qNorm.toLowerCase());
    pairs.push({ q: qNorm, a: aNorm });
  };

  // 1. FAQPage JSON-LD mainEntity
  $('script[type="application/ld+json"]').each((_, el) => {
    try {
      const data = JSON.parse($(el).text());
      const candidates = Array.isArray(data['@graph']) ? data['@graph'] : [data];
      for (const node of candidates) {
        const types = [].concat(node['@type'] || []);
        if (!types.includes('FAQPage')) continue;
        for (const entity of [].concat(node.mainEntity || [])) {
          addPair(entity.name || '', entity.acceptedAnswer?.text || '');
        }
      }
    } catch (_e) {}
  });

  // 2. <details><summary> blocks
  $('details').each((_, el) => {
    const q = $(el).find('summary').first().text();
    const a = $(el).find('p, div').first().text();
    addPair(q, a);
  });

  // 3. Accordion: aria-expanded button + sibling answer element
  $('button[aria-expanded]').each((_, el) => {
    const $btn = $(el);
    const q = $btn.text();
    const $parent = $btn.parent();
    const a = $parent.find('> div, > p, > [role="region"]').first().text()
           || $parent.next().text();
    addPair(q, a);
  });

  // 4. H3/H4 ending in "?" inside FAQ-labelled sections
  $('section, div, article').filter((_, el) => {
    const cls = ($(el).attr('class') || '');
    const id  = ($(el).attr('id') || '');
    return /faq|question/i.test(cls + id);
  }).each((_, section) => {
    $(section).find('h3, h4').each((_, hEl) => {
      const q = $(hEl).text().trim();
      if (!q.endsWith('?')) return;
      const a = $(hEl).next('p').text() || $(hEl).next().text();
      addPair(q, a);
    });
  });

  return pairs.slice(0, 6);
}

/**
 * Calculates Brand Inclusion Rate (0-10).
 * Measures how visible the brand/site is in AI-generated search results.
 */
function calculateBrandInclusion(results, siteUrl, postTitle) {
  if (!results.length) return 0;

  let score = 0;
  const siteDomain = siteUrl ? new URL(siteUrl).hostname.replace('www.', '') : '';
  const titleWords = postTitle.toLowerCase().split(/\s+/).filter(w => w.length > 3);

  for (const result of results) {
    const resultText = `${result.title || ''} ${result.content || ''} ${result.url || ''}`.toLowerCase();

    // Direct domain match (strongest signal)
    if (siteDomain && resultText.includes(siteDomain)) {
      score += 3;
    }

    // Title keyword overlap (moderate signal)
    const matchingWords = titleWords.filter(w => resultText.includes(w));
    if (matchingWords.length >= 2) {
      score += 1;
    }
  }

  return Math.min(10, Math.round(score));
}

/**
 * Identify which page builder (if any) produced the HTML.
 * Returns a specific slug ('elementor', 'divi', etc.) or '' when none is detected.
 */
function detectBuilderFromHtml(html) {
  if (/data-elementor-type|class="elementor|elementor-widget-container/i.test(html)) return 'elementor';
  if (/et_pb_section|et_pb_row|class="et_pb_/i.test(html)) return 'divi';
  if (/vc_row|wpb_wrapper|vc_column/i.test(html)) return 'wpbakery';
  if (/fl-builder|fl-module/i.test(html)) return 'beaver';
  if (/class="brxe-|bricks-element/i.test(html)) return 'bricks';
  if (/oxygen-vsb|ct-section|class="ct-/i.test(html)) return 'oxygen';
  if (/fusion-builder|fusion-layout-column/i.test(html)) return 'fusion';
  // Generic fallback for unknown/ambiguous builder markers
  if (/\b(_elementor|et_pb_|fl-builder|wpb_wrapper)\b/i.test(html)) return 'page_builder';
  return '';
}

/**
 * Build a layout map from rendered HTML for safe GEO block placement.
 *
 * @param {string} htmlContent  Rendered HTML of the page.
 * @param {string} builderHint  Builder name hint from post meta (helps when HTML is cached/minified).
 */
function analyzePageLayout(htmlContent, builderHint = '') {
  const $ = cheerio.load(htmlContent || '');
  const root = $('.entry-content, .wp-block-post-content, article, main').first();
  const scope = root.length ? root : $('body');

  const html = htmlContent || '';
  const builderDetected = detectBuilderFromHtml(html) || builderHint || '';
  const contentEditSafe = !builderDetected;

  const testimonialRe = /testimonial|review|what patients say|patient stories|our reviews/i;
  const ctaRe = /contact|book (now|an appointment|online)|schedule|get in touch|request appointment|find us|location/i;

  const sections = [];
  scope.find('h2, h3').each((i, el) => {
    const label = $(el).text().replace(/\s+/g, ' ').trim().substring(0, 80);
    if (!label || label.length < 3) return;
    const lower = label.toLowerCase();
    let type = 'content';
    if (testimonialRe.test(lower) || $(el).closest('[class*="testimonial"], [class*="review"]').length) {
      type = 'testimonial';
    } else if (ctaRe.test(lower) || $(el).nextAll('form, iframe').length) {
      type = 'cta';
    }
    sections.push({
      id: `sec_${i + 1}`,
      label,
      type,
      safe_for_faq_after: type === 'content',
    });
  });

  const ctaSection = sections.find(s => s.type === 'cta');
  let confidence = 'high';
  if (builderDetected) confidence = 'low';
  else if (sections.length === 0) confidence = 'medium';

  let recommendedStrategy = 'append_end';
  if (confidence === 'low') recommendedStrategy = 'append_end';
  else if (ctaSection) recommendedStrategy = 'append_before_cta';

  return {
    builder_detected: builderDetected,
    content_edit_safe: contentEditSafe,
    confidence,
    recommended_strategy: recommendedStrategy,
    sections,
    default_faq_placement: ctaSection ? `before:${ctaSection.id}` : 'append_end',
  };
}

/**
 * Analyzes the post live HTML for the 5-pillar GEO quality signals using Cheerio.
 */
function analyzeContentSignals(htmlContent, title, practiceProfile = null) {
  const $ = cheerio.load(htmlContent || '');

  // Re-load original HTML to check head for schema
  const $full = cheerio.load(htmlContent || '');

  // Remove noise
  $('script, style, noscript, nav, footer, header, aside').remove();
  const plainText = $('body').text() || '';
  const cleanText = plainText.replace(/\s+/g, ' ').trim();
  const wordCount = cleanText.split(/\s+/).filter(w => w.length > 0).length;

  // ── 1. Technical Crawlability ──
  const images = $('img');
  const imageCount = images.length;
  const imagesWithAlt = images.filter((i, el) => {
    const alt = $(el).attr('alt');
    return alt && alt.trim().length > 3;
  }).length;
  const altTextCoverage = imageCount > 0 ? Math.round((imagesWithAlt / imageCount) * 100) : 100;

  const hasMetaRobotsBlock = $full('meta[name="robots"][content*="noindex"]').length > 0 ||
                              $full('meta[name="robots"][content*="nofollow"]').length > 0;
  const hasLlmsTxtRef = /llms\.txt/i.test(htmlContent);

  // ── 2. Structured Data & Schema ──
  const schemaScripts = $full('script[type="application/ld+json"]');
  const hasSchema = schemaScripts.length > 0;
  let hasFaqSchema = false;
  let hasOrgSchema = false;
  let hasHealthcareSchema = false;
  schemaScripts.each((i, el) => {
    const txt = $full(el).text();
    if (/FAQPage/i.test(txt)) hasFaqSchema = true;
    if (/Organization|LocalBusiness|Product|Person/i.test(txt)) hasOrgSchema = true;
    // Expanded healthcare schema types (Phase 3)
    if (/Dentist|Physician|MedicalClinic|MedicalBusiness|Hospital|MedicalOrganization|HealthAndBeautyBusiness|DentistOffice|PhysicianOffice|MedicalProcedure|MedicalCondition/i.test(txt)) hasHealthcareSchema = true;
  });

  // ── 3. Content Quality ──
  const paragraphs = $('p').map((i, el) => $(el).text().trim()).get().filter(p => p.length > 20);
  const avgParagraphLength = paragraphs.length > 0
    ? Math.round(paragraphs.reduce((sum, p) => sum + p.split(/\s+/).length, 0) / paragraphs.length)
    : 0;

  // Direct answer detection: first substantial paragraph is 60-100 words (inverted pyramid)
  const firstParaWords = paragraphs.length > 0 ? paragraphs[0].split(/\s+/).length : 0;
  let hasDirectAnswer = firstParaWords >= 40 && firstParaWords <= 120;
  if (/gleo-direct-answer|gleo-opening-summary-wrap|gleo-ai-only|gleo:ai-overview/i.test(htmlContent || '')) {
    hasDirectAnswer = true;
  }
  if ($full('meta[name="gleo:ai-overview"]').length > 0) {
    hasDirectAnswer = true;
  }

  // Conversational query targeting
  const hasConversationalQueries = /\b(best|how to|what is|why|can i|should i|compared to|vs\.?|for a)\b/i.test(cleanText);

  // Check for TL;DR or summary blocks
  const hasTldr = /tl;?dr|at a glance|quick answer|in\s+brief/i.test(cleanText) ||
    /gleo-opening-summary-wrap|gleo-direct-answer|gleo-ai-only/i.test(htmlContent || '') ||
    $full('meta[name="gleo:ai-overview"]').length > 0;

  const hasDirectAnswers = /\b(is|are|was|were|can|does|do|will|how|what|why|when|where)\b[^.?]*\?/i.test(cleanText);

  // ── 4. Credibility ──
  const statsMatches = cleanText.match(/\d+%|\d+\s*(percent|million|billion|thousand)/ig);
  const statCount = statsMatches ? statsMatches.length : 0;
  const hasStatistics = statCount > 0;

  const citationCount = $('a[href^="http"]').length;
  const hasCitations = citationCount > 0;

  const hasQuotes = $('blockquote').length > 0 || /"[^"]{20,}"/.test(cleanText);

  // ── 5. AI-Specific Formatting ──
  const headingCount = $('h2, h3, h4, h5, h6').length;
  const hasHeadings = headingCount > 0;

  const listCount = $('ul, ol').length;
  const hasList = listCount > 0;

  // has_table removed — comparison tables are no longer generated or scored
  const effectiveHeadingCount = Math.max(
    headingCount,
    $('h2.gleo-section-heading, .gleo-section-heading').length + $('h3, h4').length
  );

  const hasFAQ = /faq|frequently\s+asked|common\s+questions|gleo-faq/i.test(cleanText) ||
    /gleo-faq-wrap|gleo-faq-accordion/i.test(htmlContent || '');

  // Long paragraph detection (paragraphs > 80 words)
  const longParagraphs = paragraphs.filter(p => p.split(/\s+/).length > 80).length;

  // ── Practice Profile Signals (Phase 2) ──
  // NAP: phone number pattern + street address keywords in visible text
  const hasNapSignals = /\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}|\+1[\s.\-]\d{3}[\s.\-]\d{3}[\s.\-]\d{4}/i.test(cleanText) &&
    /\b(street|st\.|avenue|ave\.|boulevard|blvd|road|rd\.|drive|dr\.|suite|ste\.)\b/i.test(cleanText);

  // Hours: openingHoursSpecification in JSON-LD or common hours text patterns
  let hasHoursSignals = false;
  schemaScripts.each((_, el) => {
    if (/openingHoursSpecification|openingHours/i.test($full(el).text())) {
      hasHoursSignals = true;
    }
  });
  if (!hasHoursSignals) {
    hasHoursSignals = /\b(mon|tue|wed|thu|fri|sat|sun)\w*[\s:]+\d{1,2}(:\d{2})?\s*(am|pm)/i.test(cleanText) ||
      /hours?\s*:|\bopen\s+\d|\boffice\s+hours\b/i.test(cleanText);
  }

  // Booking link: matches profile URL or generic booking service patterns
  const bookingUrl = practiceProfile?.booking_url || '';
  let hasBookingLink = false;
  if (bookingUrl) {
    try {
      const bookingHostname = new URL(bookingUrl).hostname;
      $('a[href]').each((_, el) => {
        const href = $full(el).attr('href') || '';
        try { if (new URL(href).hostname === bookingHostname) hasBookingLink = true; } catch (_) {}
      });
    } catch (_) {}
  }
  if (!hasBookingLink) {
    hasBookingLink = $('a[href]').toArray().some(el => {
      const href = ($full(el).attr('href') || '').toLowerCase();
      return /\/book|\/schedule|\/appointment|zocdoc\.com|localmed\.com|patientfusion\.com|healthgrades\.com\/appointment|opendental/i.test(href);
    });
  }

  // ── Phase 3: Healthcare-specific signals ──

  // Insurance: profile payer names on page, or generic insurance language
  let hasInsuranceSignals = false;
  const profileInsurers = (practiceProfile?.insurance_accepted || []).filter(Boolean);
  if (profileInsurers.length > 0) {
    hasInsuranceSignals = profileInsurers.some(ins => {
      const safe = ins.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      return new RegExp(safe, 'i').test(cleanText);
    });
  }
  if (!hasInsuranceSignals) {
    hasInsuranceSignals = /\b(in[- ]?network|accepts?\s+(insurance|most\s+insurance|many\s+plans)|we\s+accept|insurance\s+(accepted|welcome|coverage)|delta\s+dental|aetna|cigna|metlife|humana|guardian|principal|united\s+health|bcbs|blue\s+cross|medicaid|medicare|tricare)\b/i.test(cleanText);
  }

  // Credentials: provider credential suffixes or board-certified language
  let hasCredentialsSignals = false;
  const profileProviders = (practiceProfile?.providers || []);
  if (profileProviders.length > 0) {
    hasCredentialsSignals = profileProviders.some(p => {
      const name = (p.name || '').trim();
      return name && cleanText.includes(name);
    });
  }
  if (!hasCredentialsSignals) {
    hasCredentialsSignals = /\b(DDS|DMD|MD|DO|DPM|PA-C|NP|RN|CRNA|OD|DC|PhD)\b|board[- ]certified|fellow\s+of\s+the|residency[- ]trained|diplomate\s+of/i.test(cleanText);
  }

  // Local intent: city/state/ZIP from profile locations appear in content, or serving-area patterns
  let hasLocalIntentSignals = false;
  const profileLocations = (practiceProfile?.locations || []);
  if (profileLocations.length > 0) {
    hasLocalIntentSignals = profileLocations.some(loc => {
      const city = (loc.city || '').trim();
      const state = (loc.state || '').trim();
      const zip = (loc.zip || '').trim();
      return (city && new RegExp(`\\b${city.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i').test(cleanText))
        || (state && new RegExp(`\\b${state.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i').test(cleanText))
        || (zip && cleanText.includes(zip));
    });
  }
  if (!hasLocalIntentSignals) {
    hasLocalIntentSignals = /\b(serving|patients\s+in|near\s+[a-z]+|located\s+in|our\s+[a-z]+\s+office|families\s+in)\b/i.test(cleanText);
  }

  // Disclaimer: standard medical/dental disclaimer language, including Gleo-injected phrases.
  const hasDisclaimer = /not\s+a\s+substitute\s+for\s+professional\s+(medical|dental)\s+advice|not\s+(medical|dental)\s+advice|consult\s+(your\s+)?(doctor|dentist|physician|provider|healthcare)|for\s+informational\s+purposes|does\s+not\s+replace|individual\s+results\s+may\s+vary|speak\s+with\s+your\s+(provider|doctor|dentist)/i.test(cleanText);

  return {
    word_count: wordCount,
    // Technical
    image_count: imageCount,
    images_with_alt: imagesWithAlt,
    alt_text_coverage: altTextCoverage,
    has_meta_robots_block: hasMetaRobotsBlock,
    has_llms_txt: hasLlmsTxtRef,
    // Schema
    has_schema: hasSchema,
    has_faq_schema: hasFaqSchema,
    has_org_schema: hasOrgSchema,
    // Content Quality
    has_direct_answer: hasDirectAnswer,
    has_conversational_queries: hasConversationalQueries,
    has_tldr: hasTldr,
    has_direct_answers: hasDirectAnswers,
    first_para_words: firstParaWords,
    // Credibility
    has_statistics: hasStatistics,
    stat_count: statCount,
    has_citations: hasCitations,
    citation_count: citationCount,
    has_quotes: hasQuotes,
    // AI Formatting
    has_headings: hasHeadings,
    heading_count: effectiveHeadingCount,
    has_lists: hasList,
    list_item_count: listCount,
    has_faq: hasFAQ,
    has_images: imageCount > 0,
    paragraph_count: paragraphs.length,
    avg_paragraph_length: avgParagraphLength,
    long_paragraphs: longParagraphs,
    // Practice profile signals (Phase 2)
    has_healthcare_schema: hasHealthcareSchema,
    has_nap_signals: hasNapSignals,
    has_hours_signals: hasHoursSignals,
    has_booking_link: hasBookingLink,
    // Healthcare signals (Phase 3)
    has_insurance_signals: hasInsuranceSignals,
    has_credentials_signals: hasCredentialsSignals,
    has_local_intent_signals: hasLocalIntentSignals,
    has_disclaimer: hasDisclaimer,
  };
}

/**
 * Calculates the overall GEO score (0-100) using the 5-pillar framework.
 * When a healthcare practice profile is supplied, weights shift toward patient-intent
 * signals (FAQ, credentials, insurance, local intent) and away from raw word count.
 */
function calculateGeoScore(signals, brandRate, tavilyResults, practiceProfile = null) {
  const healthcare = isHealthcarePractice(practiceProfile);
  let score = 0;

  // ── 1. Technical Crawlability (max 15) — same for all sites ──
  if (signals.alt_text_coverage >= 90) score += 5;
  else if (signals.alt_text_coverage >= 50) score += 3;
  else if (signals.image_count === 0) score += 5;

  if (!signals.has_meta_robots_block) score += 5;
  if (signals.has_llms_txt) score += 5;

  if (healthcare) {
    // ── 2. Structured Data & Schema — healthcare (max 25) ──
    // FAQ schema and healthcare-specific types are weighted higher than for generic sites.
    let pillar2 = 0;
    if (signals.has_schema) pillar2 += 8;
    if (signals.has_faq_schema) pillar2 += 7;
    if (signals.has_org_schema) pillar2 += 4;
    if (signals.has_healthcare_schema) pillar2 += 4;
    if (signals.has_nap_signals) pillar2 += 3;
    if (signals.has_hours_signals) pillar2 += 2;
    if (signals.has_booking_link) pillar2 += 2;
    if (signals.has_insurance_signals) pillar2 += 3;
    if (signals.has_credentials_signals) pillar2 += 3;
    score += Math.min(25, pillar2);

    // ── 3. Content Quality — healthcare (max 25) ──
    // Shorter focused pages score well; long blog-style articles get less credit.
    if (signals.word_count >= 800) score += 5;
    else if (signals.word_count >= 400) score += 3;
    else if (signals.word_count > 0) score += 1;

    if (signals.has_direct_answer) score += 5;
    if (signals.has_tldr) score += 5;

    if (signals.has_conversational_queries) score += 3;
    if (signals.has_direct_answers) score += 2;

    // Local intent and FAQ presence replace the stats+quotes content-quality bucket.
    if (signals.has_local_intent_signals) score += 3;
    if (signals.has_faq) score += 2;

    // ── 4. Credibility — healthcare (max 15) ──
    // Stat rewards are lower to discourage invented medical statistics.
    if (signals.stat_count >= 3) score += 3;
    else if (signals.stat_count >= 1) score += 2;

    if (signals.citation_count >= 3) score += 5;
    else if (signals.citation_count >= 1) score += 3;

    if (signals.has_quotes) score += 5;
    if (signals.has_disclaimer) score += 2;

    // ── 5. AI-Specific Formatting — healthcare (max 20) ──
    if (signals.heading_count >= 4) score += 5;
    else if (signals.heading_count >= 2) score += 3;
    else if (signals.has_headings) score += 1;

    if (signals.long_paragraphs === 0 && signals.paragraph_count > 0) score += 5;
    else if (signals.long_paragraphs <= 2) score += 3;

    if (signals.list_item_count >= 3) score += 4;
    else if (signals.has_lists) score += 2;

    // FAQ block worth 8 pts (up from 6) — patient FAQ is the primary content format.
    if (signals.has_faq) score += 8;
  } else {
    // ── 2. Structured Data & Schema — standard (max 20) ──
    let pillar2 = 0;
    if (signals.has_schema) pillar2 += 10;
    if (signals.has_faq_schema) pillar2 += 5;
    if (signals.has_org_schema) pillar2 += 5;
    if (signals.has_nap_signals) pillar2 += 3;
    if (signals.has_hours_signals) pillar2 += 2;
    if (signals.has_booking_link) pillar2 += 2;
    score += Math.min(20, pillar2);

    // ── 3. Content Quality — standard (max 30) ──
    if (signals.word_count >= 2000) score += 10;
    else if (signals.word_count >= 1200) score += 7;
    else if (signals.word_count >= 600) score += 4;
    else if (signals.word_count > 0) score += 1;

    if (signals.has_direct_answer) score += 5;
    if (signals.has_tldr) score += 5;

    if (signals.has_conversational_queries) score += 3;
    if (signals.has_direct_answers) score += 2;

    if (signals.word_count >= 800 && signals.has_statistics) score += 3;
    if (signals.has_quotes) score += 2;

    // ── 4. Credibility — standard (max 15) ──
    if (signals.stat_count >= 3) score += 5;
    else if (signals.stat_count >= 1) score += 3;

    if (signals.citation_count >= 3) score += 5;
    else if (signals.citation_count >= 1) score += 3;

    if (signals.has_quotes) score += 5;

    // ── 5. AI-Specific Formatting — standard (max 20) ──
    if (signals.heading_count >= 4) score += 5;
    else if (signals.heading_count >= 2) score += 3;
    else if (signals.has_headings) score += 1;

    if (signals.long_paragraphs === 0 && signals.paragraph_count > 0) score += 5;
    else if (signals.long_paragraphs <= 2) score += 3;

    if (signals.list_item_count >= 3) score += 4;
    else if (signals.has_lists) score += 2;

    if (signals.has_faq) score += 6;
  }

  return Math.min(100, score);
}

/**
 * Generates JSON-LD structured data schema for the post.
 */
function generateJsonLd(title, content, siteUrl) {
  const $ = cheerio.load(content || '');
  $('script, style, noscript, svg, path, iframe, nav, footer, header, aside').remove();

  const articleRoot =
    $('.entry-content').first().length ? $('.entry-content').first() :
    $('.wp-block-post-content').first().length ? $('.wp-block-post-content').first() :
    $('article').first().length ? $('article').first() :
    $('main').first().length ? $('main').first() :
    $('body').first();

  const cleanText = articleRoot.text().replace(/\s+/g, ' ').trim();
  const textWords = cleanText.split(/\s+/).filter(Boolean);
  const wordCount = textWords.length;

  const normalizedTitle = (title || '').replace(/\s+/g, ' ').trim();
  const fallbackTitle = normalizedTitle || cleanText.split(/[.!?]/)[0]?.trim() || 'Article';
  const description = (cleanText.slice(0, 220) || fallbackTitle).trim();

  const pageUrl = (() => {
    try {
      return siteUrl ? new URL(siteUrl).toString() : '';
    } catch (e) {
      return siteUrl || '';
    }
  })();

  const orgName = (() => {
    try {
      return siteUrl ? new URL(siteUrl).hostname : 'Publisher';
    } catch (e) {
      return 'Publisher';
    }
  })();

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: fallbackTitle,
    description,
    wordCount,
    author: {
      '@type': 'Organization',
      name: orgName
    },
    datePublished: new Date().toISOString(),
    mainEntityOfPage: {
      '@type': 'WebPage',
      '@id': pageUrl
    }
  };

  // Add FAQ only when explicit FAQ-style Q/A exists in article content.
  const faqQuestions = [];
  articleRoot.find('h3, h4, strong').each((_, el) => {
    const q = $(el).text().replace(/\s+/g, ' ').trim();
    if (!q || q.length < 12 || q.length > 180) return;
    if (!/[?]$/.test(q) && !/^(what|how|why|when|where|who|can|does|is|are)\b/i.test(q)) return;
    const bad = /\b(home|about|menu|reviews|contact|privacy|terms|wordpress)\b/i.test(q);
    if (bad) return;
    faqQuestions.push(q);
  });
  const uniqueFaq = [...new Set(faqQuestions)].slice(0, 5);
  if (uniqueFaq.length >= 2) {
    schema['@type'] = ['Article', 'FAQPage'];
    schema.mainEntity = uniqueFaq.map(q => ({
      '@type': 'Question',
      name: q,
      acceptedAnswer: {
        '@type': 'Answer',
        text: 'See the article section for the detailed answer.'
      }
    }));
  }

  return schema;
}

/**
 * Generates specific, actionable GEO recommendations based on the 5-pillar framework.
 * Healthcare practice profiles receive patient-focused guidance.
 */
function generateRecommendations(signals, brandRate, geoScore, practiceProfile = null, layoutMap = null) {
  const healthcare = isHealthcarePractice(practiceProfile);
  const recs = [];

  // ── Page builder notice (prepended so it is always the first recommendation) ──
  if (layoutMap && layoutMap.content_edit_safe === false) {
    const builderName = layoutMap.builder_detected || 'a page builder';
    const builderLabel = builderName === 'page_builder' ? 'a page builder' : builderName.charAt(0).toUpperCase() + builderName.slice(1);
    recs.push({
      priority: 'info',
      area: 'Page Builder Detected',
      score: null,
      maxScore: null,
      message: `${builderLabel} detected. Gleo will apply schema and metadata fixes automatically. Content blocks (FAQ, depth, quotes) are appended safely at page end; in-place edits (formatting, readability) are shown as copy-paste suggestions to avoid breaking your layout.`,
    });
  }

  // ── 1. Technical Crawlability (max 15) — same for all sites ──
  {
    let score = 0;
    if (!signals.has_meta_robots_block) score += 5;
    if (signals.alt_text_coverage >= 90 || signals.image_count === 0) score += 5;
    if (signals.has_llms_txt) score += 5;

    if (score < 15) {
      const issues = [];
      if (signals.has_meta_robots_block) issues.push('Remove robots meta noindex/nofollow — AI bots need access');
      if (signals.image_count > 0 && signals.alt_text_coverage < 90) issues.push(`Only ${signals.alt_text_coverage}% of images have descriptive alt text`);
      if (!signals.has_llms_txt) issues.push('No /llms.txt reference in page HTML — Gleo serves /llms.txt and adds a head link; re-scan after deploy');
      recs.push({
        priority: score <= 5 ? 'critical' : 'medium',
        area: 'Technical & Crawlability',
        score, maxScore: 15,
        message: issues.join('. ') + '.'
      });
    }
  }

  // ── 2. Structured Data & Schema ──
  if (healthcare) {
    let score = 0;
    if (signals.has_schema) score += 8;
    if (signals.has_faq_schema) score += 7;
    if (signals.has_org_schema) score += 4;
    if (signals.has_healthcare_schema) score += 4;
    if (signals.has_nap_signals) score += 3;
    if (signals.has_hours_signals) score += 2;
    if (signals.has_booking_link) score += 2;
    if (signals.has_insurance_signals) score += 3;
    if (signals.has_credentials_signals) score += 3;
    score = Math.min(25, score);

    if (score < 25) {
      const issues = [];
      if (!signals.has_schema) issues.push('Deploy JSON-LD schema so AI engines understand your practice');
      if (!signals.has_healthcare_schema) issues.push('Add Dentist/Physician/MedicalClinic schema type so AI correctly categorizes your practice');
      if (!signals.has_faq_schema) issues.push('Add FAQPage schema — patient FAQ is the primary content AI surfaces for medical queries');
      if (!signals.has_org_schema) issues.push('Add LocalBusiness or MedicalOrganization schema');
      if (!signals.has_nap_signals) issues.push('Add your practice name, address, and phone to this page');
      if (!signals.has_hours_signals) issues.push('List office hours so patients find your schedule in AI results');
      if (!signals.has_booking_link) issues.push('Add a booking link so AI can recommend scheduling an appointment');
      if (!signals.has_insurance_signals) issues.push('List accepted insurance plans — patients routinely ask AI "do you accept my insurance?"');
      if (!signals.has_credentials_signals) issues.push('Mention provider credentials (DDS, MD, board-certified) — builds patient trust in AI responses');
      recs.push({
        priority: !signals.has_schema ? 'critical' : 'medium',
        area: 'Technical & Schema',
        score, maxScore: 25,
        message: issues.join('. ') + '.'
      });
    }
  } else {
    let score = 0;
    if (signals.has_schema) score += 10;
    if (signals.has_faq_schema) score += 5;
    if (signals.has_org_schema) score += 5;
    if (signals.has_nap_signals) score += 3;
    if (signals.has_hours_signals) score += 2;
    if (signals.has_booking_link) score += 2;
    score = Math.min(20, score);

    if (score < 20) {
      const issues = [];
      if (!signals.has_schema) issues.push('Deploy JSON-LD schema markup so AI understands your content');
      if (!signals.has_faq_schema) issues.push('Add FAQPage schema — matches the Q&A format AI loves');
      if (!signals.has_org_schema) issues.push('Add Organization/LocalBusiness/Product schemas as needed');
      if (!signals.has_nap_signals) issues.push('Add your practice name, address, and phone to this page');
      if (!signals.has_hours_signals) issues.push('List office hours on this page so patients find them in AI results');
      if (!signals.has_booking_link) issues.push('Add a booking link so AI can recommend appointment scheduling');
      recs.push({
        priority: !signals.has_schema ? 'critical' : 'medium',
        area: 'Technical & Schema',
        score, maxScore: 20,
        message: issues.join('. ') + '.'
      });
    }
  }

  // ── 3. Content Quality ──
  if (healthcare) {
    let score = 0;
    if (signals.word_count >= 800) score += 5;
    else if (signals.word_count >= 400) score += 3;
    else if (signals.word_count > 0) score += 1;
    if (signals.has_direct_answer) score += 5;
    if (signals.has_tldr) score += 5;
    if (signals.has_conversational_queries) score += 3;
    if (signals.has_direct_answers) score += 2;
    if (signals.has_local_intent_signals) score += 3;
    if (signals.has_faq) score += 2;
    score = Math.min(25, score);

    if (score < 25) {
      const issues = [];
      if (signals.word_count < 400) issues.push(`Content is ${signals.word_count} words — add patient-facing detail (insurance, recovery, what to expect)`);
      if (!signals.has_direct_answer) issues.push('Open with a clear 60-word answer to the patient\'s main question');
      if (!signals.has_tldr) issues.push('Add an AI-readable summary of what this page covers');
      if (!signals.has_conversational_queries) issues.push('Use natural patient language — "how much does," "do you accept," "is it painful"');
      if (!signals.has_local_intent_signals) issues.push('Mention your city, neighborhood, or service area — local intent drives healthcare searches');
      if (!signals.has_faq) issues.push('Add a patient FAQ section — it\'s the format AI prefers for medical queries');
      recs.push({
        priority: score <= 10 ? 'critical' : score <= 18 ? 'high' : 'medium',
        area: 'Content Writing & Substance',
        score, maxScore: 25,
        message: issues.join('. ') + '.'
      });
    }
  } else {
    let score = 0;
    if (signals.word_count >= 2000) score += 10;
    else if (signals.word_count >= 1200) score += 7;
    else if (signals.word_count >= 600) score += 4;
    else if (signals.word_count > 0) score += 1;
    if (signals.has_direct_answer) score += 5;
    if (signals.has_tldr) score += 5;
    if (signals.has_conversational_queries) score += 3;
    if (signals.has_direct_answers) score += 2;
    if (signals.word_count >= 800 && signals.has_statistics) score += 3;
    if (signals.has_quotes) score += 2;
    score = Math.min(30, score);

    if (score < 30) {
      const issues = [];
      if (signals.word_count < 1200) issues.push(`Content is ${signals.word_count} words — aim for 1,200+ with depth`);
      if (!signals.has_direct_answer) issues.push('Put a 60-100 word direct answer at the very top (inverted pyramid)');
      if (!signals.has_tldr) issues.push('Add an AI-readable summary (stored for crawlers in page metadata, not as a visible promo box)');
      if (!signals.has_conversational_queries) issues.push('Target long-tail conversational queries users ask AI');
      recs.push({
        priority: score <= 10 ? 'critical' : score <= 20 ? 'high' : 'medium',
        area: 'Content Writing & Substance',
        score, maxScore: 30,
        message: issues.join('. ') + '.'
      });
    }
  }

  // ── 4. Credibility ──
  if (healthcare) {
    let score = 0;
    if (signals.stat_count >= 3) score += 3;
    else if (signals.stat_count >= 1) score += 2;
    if (signals.citation_count >= 3) score += 5;
    else if (signals.citation_count >= 1) score += 3;
    if (signals.has_quotes) score += 5;
    if (signals.has_disclaimer) score += 2;

    if (score < 15) {
      const issues = [];
      if (signals.stat_count < 1) issues.push('Include practice-specific statistics (e.g. years in practice, procedures performed) — avoid invented or unverifiable claims');
      if (signals.citation_count < 3) issues.push('Link to authoritative sources such as the ADA, AAP, or AAFP for clinical claims');
      if (!signals.has_quotes) issues.push('Include a provider quote or verified patient testimonial');
      if (!signals.has_disclaimer) issues.push('Add a brief disclaimer — e.g. "This is general information; consult your provider for personal guidance"');
      recs.push({
        priority: score <= 5 ? 'high' : 'medium',
        area: 'Trust & Brand Signals',
        score, maxScore: 15,
        message: issues.join('. ') + '.'
      });
    }
  } else {
    let score = 0;
    if (signals.stat_count >= 3) score += 5;
    else if (signals.stat_count >= 1) score += 3;
    if (signals.citation_count >= 3) score += 5;
    else if (signals.citation_count >= 1) score += 3;
    if (signals.has_quotes) score += 5;

    if (score < 15) {
      const issues = [];
      if (signals.stat_count < 3) issues.push('Add unique first-party statistics — AI craves data it hasn\'t seen');
      if (signals.citation_count < 3) issues.push('Add outbound links to authoritative sources for credibility');
      if (!signals.has_quotes) issues.push('Include expert quotes or real testimonials');
      recs.push({
        priority: score <= 5 ? 'high' : 'medium',
        area: 'Trust & Brand Signals',
        score, maxScore: 15,
        message: issues.join('. ') + '.'
      });
    }
  }

  // ── 5. AI-Specific Formatting (max 20) — same structure, healthcare FAQ worth more ──
  {
    const faqPts = healthcare ? 8 : 6;
    let score = 0;
    if (signals.heading_count >= 4) score += 5;
    else if (signals.heading_count >= 2) score += 3;
    else if (signals.has_headings) score += 1;
    if (signals.long_paragraphs === 0 && signals.paragraph_count > 0) score += 5;
    else if (signals.long_paragraphs <= 2) score += 3;
    if (signals.list_item_count >= 3) score += 4;
    else if (signals.has_lists) score += 2;
    if (signals.has_faq) score += faqPts;
    score = Math.min(20, score);

    if (score < 20) {
      const issues = [];
      if (signals.heading_count < 4) issues.push(`Only ${signals.heading_count} headings — add H2s every ~3 paragraphs`);
      if (signals.long_paragraphs > 0) issues.push(`${signals.long_paragraphs} paragraph(s) exceed 80 words — shorten them`);
      if (!signals.has_lists) issues.push('Convert dense paragraphs into bulleted lists');
      if (!signals.has_faq) {
        issues.push(healthcare
          ? 'Add a patient FAQ section — it\'s the format AI engines prefer for healthcare queries'
          : 'Inject a contextual FAQ block near the end');
      }
      recs.push({
        priority: score <= 8 ? 'high' : 'medium',
        area: 'Structure & Formatting',
        score, maxScore: 20,
        message: issues.join('. ') + '.'
      });
    }
  }

  return recs;
}

module.exports = {
  isHealthcarePractice,
  extractExistingFaqs,
  calculateBrandInclusion,
  detectBuilderFromHtml,
  analyzePageLayout,
  analyzeContentSignals,
  calculateGeoScore,
  generateJsonLd,
  generateRecommendations,
};
