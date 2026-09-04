/**
 * Per-post GEO analysis: Tavily topic search plus Gemini content generation
 * layered on top of the pure scoring primitives in lib/geo-scoring.js.
 *
 * Scoring itself lives in lib/geo-scoring.js and has no network or LLM
 * dependency. Anything that only needs a score should require that module
 * directly rather than this one.
 */

const axios = require('axios');
const cheerio = require('cheerio');
const { GoogleGenAI } = require('@google/genai');

const {
  isHealthcarePractice,
  extractExistingFaqs,
  calculateBrandInclusion,
  analyzePageLayout,
  analyzeContentSignals,
  calculateGeoScore,
  generateJsonLd,
  generateRecommendations,
} = require('./geo-scoring');

const TAVILY_API_KEY = process.env.TAVILY_API_KEY;
const TAVILY_SEARCH_URL = 'https://api.tavily.com/search';

// Constructed on first use so that requiring this module without a Gemini key
// does not throw — callers that only need scoring never touch the client.
let aiClient = null;
function getAiClient() {
  if (!aiClient) {
    aiClient = new GoogleGenAI({ apiKey: process.env.GOOGLE_GENAI_API_KEY });
  }
  return aiClient;
}

const GENERIC_COPY_PATTERNS = [
  /awaken your senses/i,
  /finest artisanal/i,
  /perfect morning/i,
  /why choose us/i,
  /deeper dive/i,
  /elevate your/i,
  /crafted with passion/i,
  /unleash/i,
  /experience the/i,
  /a closer look/i,
  /closer look at/i,
  /key details/i,
  /what you need to know/i,
  /important considerations/i,
  /key takeaways/i,
  /deep dive/i,
  /data overview/i,
];

// Risky medical claim patterns that should never appear in AI-generated copy.
const RISKY_MEDICAL_CLAIM_PATTERNS = [
  /\bguaranteed\b/i,
  /\bcure[sd]?\b/i,
  /\balways works\b/i,
  /\bno risk\b/i,
  /\bwill eliminate\b/i,
  /\bpermanently fix(es)?\b/i,
  /\bcompletely pain[- ]free\b/i,
  /\b100%\s+(effective|success|guaranteed|painless)\b/i,
  /\bfda[- ]approved\b/i,
];

function looksGenericCopy(html = '') {
  if (!html || typeof html !== 'string') return false;
  return GENERIC_COPY_PATTERNS.some((re) => re.test(html));
}

/**
 * Returns true when generated medical copy makes absolute outcome claims or
 * guarantee language not present in the source excerpt.
 */
function looksMedicalClaim(html = '', sourceExcerpt = '') {
  if (!html || typeof html !== 'string') return false;
  return RISKY_MEDICAL_CLAIM_PATTERNS.some((re) => {
    if (!re.test(html)) return false;
    // Only block claims that were invented — not language already in the source.
    return !re.test(sourceExcerpt);
  });
}

/** Instructional / editor-placeholder “statistics” the model must not emit (or we strip). */
function looksStatInstructionPlaceholder(text = '') {
  const t = String(text || '')
    .toLowerCase()
    .replace(/\s+/g, ' ');
  return ['add a verified', 'source-backed metric', 'figure and source name', 'verified, source-backed', 'include the figure'].some((n) => t.includes(n));
}

function sanitizeContextualAssets(assets, isHealthcare = false, sourceExcerpt = '') {
  if (!assets || typeof assets !== 'object') return null;
  const keys = ['faq_html', 'depth_html', 'qa_html', 'authority_html'];
  const out = { ...assets };
  let hasAny = false;
  for (const key of keys) {
    if (!out[key] || typeof out[key] !== 'string') {
      out[key] = '';
      continue;
    }
    if (looksGenericCopy(out[key])) {
      out[key] = '';
      continue;
    }
    if (isHealthcare && looksMedicalClaim(out[key], sourceExcerpt)) {
      out[key] = '';
      continue;
    }
    hasAny = true;
  }
  if (looksStatInstructionPlaceholder(out.authority_html)) {
    out.authority_html = '';
  }
  // In healthcare mode, strip authority_html when it invents numbers not present in the source.
  if (isHealthcare && out.authority_html) {
    const numbers = (out.authority_html.match(/\d[\d,.]*%?/g) || []);
    if (numbers.length > 0 && numbers.every(n => !sourceExcerpt.includes(n))) {
      out.authority_html = '';
    }
  }
  return hasAny ? out : null;
}

/**
 * Generates specifically contextual HTML elements dynamically based on the post.
 * When a healthcare practice profile is provided, uses patient-intent prompts
 * and YMYL-safe guardrails.
 */
async function generateContextualAssets(title, content, layoutMap = null, practiceProfile = null) {
  const $ = cheerio.load(content || '');
  $('script, style, noscript, svg, path, iframe, nav, footer, header, aside').remove();
  const plainText = $('body').text().replace(/\s+/g, ' ').substring(0, 3000).trim();
  const layoutHint = layoutMap?.sections?.length
    ? `\nPage sections (context only — FAQ goes in a dedicated block): ${layoutMap.sections.map(s => s.label).join(', ')}.`
    : '';

  const isHealthcare = isHealthcarePractice(practiceProfile);

  let systemInstruction, userPrompt, faqDescription, authorityDescription;

  if (isHealthcare) {
    const practiceCity = (practiceProfile?.locations?.[0]?.city || '').trim();
    const specialty = (practiceProfile?.specialty || practiceProfile?.practice_type || '').trim();
    const targetQueries = (practiceProfile?.target_queries || []).filter(Boolean).slice(0, 3);
    const seedQuestionsHint = targetQueries.length
      ? `\nPatient queries to consider seeding into FAQ (use if relevant to the article): ${targetQueries.map(q => `"${q}"`).join(', ')}.`
      : '';
    const contextHint = [practiceCity, specialty].filter(Boolean).join(', ');

    systemInstruction = "You are a patient-facing healthcare content editor. Write clear, reassuring copy a patient would actually read. No academic language, no invented statistics, no medical outcome guarantees. Every claim must be grounded in the article excerpt. Return strict JSON only.";

    userPrompt = `Article title: ${title}\nArticle excerpt: ${plainText}${layoutHint}${seedQuestionsHint}\nPractice context: ${contextHint || 'healthcare practice'}\n\nWrite HTML snippets for a healthcare practice website. Generated HTML is content-only — placed in a dedicated section, not mid-article. FAQ: H3 questions only (no H2 wrapper).\n\nBANNED phrases: "a closer look", "key details", "deep dive", "why choose us", "elevate your", "mechanism of", "pathophysiology", "overview of", "etiology", "in summary". Banned headings: "How X works", "Overview of X", "What is X".\n\nBANNED in any field: guaranteed results, cure, "no risk", "100% effective", "will eliminate", "permanently fix", "completely pain-free", "FDA-approved" (unless in the excerpt).\n\nFAQ questions MUST sound like real patient Google searches — about cost, insurance acceptance, pain, recovery time, sedation options, referrals, or appointment availability. Never use academic or research phrasing.\n\nFor any clinical answer, end with: "Speak with your provider for advice specific to your situation."\n\nNever output stat placeholder instructions. Only include statistics that appear in the excerpt.\n\nInclude: (1) FAQ 2–3 H3 patient questions, (2) one H2 + paragraph grounded in the excerpt, (3) compact Q&A block with a patient question, (4) one <p> with stats only if the excerpt contains numbers — otherwise <p></p>.`;

    faqDescription = "FAQ block: 2–3 H3 patient questions only (no H2 wrapper). Questions must read like real patient Google searches — e.g. 'Does [procedure] hurt?', 'Do you accept insurance for [topic]?', 'How long is recovery after [topic]?', 'How much does [topic] cost?'. Never academic, never guarantee outcomes. If a clinical answer is included, it must end with 'Speak with your provider for advice specific to your situation.'";
    authorityDescription = "Single <p> only: include 1–2 real numeric statistics that appear verbatim in the article excerpt, written as natural prose. If no numbers appear in the excerpt, output <p></p>. Never invent statistics.";
  } else {
    systemInstruction = "You are a senior editor for a small business website. Write natural, trustworthy copy that matches the excerpt. No SEO filler, no robotic templates, no generic section titles. Return strict JSON only.";

    userPrompt = `Article title: ${title}\nArticle excerpt: ${plainText}${layoutHint}\n\nWrite HTML snippets for WordPress. Generated HTML is content-only — Gleo places it in a dedicated section, not mid-article. FAQ: H3 questions only (no H2 wrapper).\n\nBanned phrases: "a closer look", "key details", "what you need to know", "deep dive", "why choose us", "elevate your". Banned headings: "How X works", "Overview of X", "What is X".\n\nFAQ questions must read like real Google searches. Never output stat placeholder instructions.\n\nInclude: (1) FAQ 2–3 H3 questions, (2) one H2 + paragraph, (3) compact Q&A block, (4) one <p> with stats if excerpt supports it.`;

    faqDescription = "FAQ block: 2–3 H3 questions only (no H2 wrapper — the title is added separately). Each question must read exactly like a real customer Google search (e.g. 'How much does X cost?', 'Do you offer same-day X?', 'What's included in X?'). Never use academic, generic, or 'how X works' phrasing.";
    authorityDescription = "Single <p> only: 2–3 real numeric statistics grounded in the excerpt as flowing prose; never instructions to the editor; use <p></p> if no numbers exist in the excerpt.";
  }

  try {
    const response = await getAiClient().models.generateContent({
      model: "gemini-2.5-flash",
      contents: [
        { role: "user", parts: [{ text: userPrompt }] }
      ],
      config: {
        systemInstruction,
        responseMimeType: "application/json",
        responseSchema: {
          type: "OBJECT",
          properties: {
            faq_html: { type: "STRING", description: faqDescription },
            depth_html: { type: "STRING", description: "One H2 with a specific, topic-grounded title (never 'How X works', 'Background on X', or any generic section label) plus one <p> that adds genuinely useful detail grounded in the excerpt." },
            qa_html: { type: "STRING", description: "Short Q&A block: natural question heading plus direct answer paragraph about the article's core idea." },
            authority_html: { type: "STRING", description: authorityDescription }
          },
          required: ["faq_html", "depth_html", "qa_html", "authority_html"]
        }
      }
    });

    try {
      return sanitizeContextualAssets(JSON.parse(response.text), isHealthcare, plainText);
    } catch (e) {
      console.error('[GEO] Failed to parse Gemini response:', e.message);
      throw new Error("Gemini parsing failed");
    }
  } catch (err) {
    console.error('[GEO] Gemini API failed:', err.message);
    return null;
  }
}

/**
 * Analyzes a single post for Generative Engine Optimization (GEO).
 * Uses Tavily to understand how AI engines see the post's topic,
 * then scores the post and generates actionable recommendations based on live HTML.
 *
 * @param {Object} post - { id, title, content (Live HTML) }
 * @param {string} siteUrl - The WordPress site URL for brand detection
 * @param {Object} [practiceProfile] - Optional practice profile from WordPress
 * @returns {Object} Full GEO report for this post
 */
async function analyzePost(post, siteUrl = '', practiceProfile = null) {
  const { id, title, content } = post;
  console.log(`  [GEO] Analyzing post ${id}: "${title}"`);

  // --- Step 1: Tavily Search - How do AI engines respond to this topic? ---
  let tavilyResults = [];
  try {
    const searchQuery = title.length > 10 ? title : `${title} ${content.substring(0, 100)}`;
    const response = await axios.post(TAVILY_SEARCH_URL, {
      api_key: TAVILY_API_KEY,
      query: searchQuery,
      search_depth: 'advanced',
      include_answer: true,
      include_raw_content: false,
      max_results: 5
    });
    tavilyResults = response.data.results || [];
    console.log(`  [GEO] Tavily returned ${tavilyResults.length} results for "${title}"`);
  } catch (err) {
    console.error(`  [GEO] Tavily search failed for post ${id}:`, err.message);
  }

  // --- Step 2: Brand Inclusion Rate (0-10) ---
  const brandInclusionRate = calculateBrandInclusion(tavilyResults, siteUrl, title);

  // --- Step 3: Content Quality Signals (HTML Parsing) ---
  const contentSignals = analyzeContentSignals(content, title, practiceProfile);

  // --- Step 3b: Page layout map for safe content placement ---
  // builder_meta hint from the scanner (post meta) helps when rendered HTML is cached/minified.
  const layoutMap = analyzePageLayout(content, post.builder_meta || '');

  // --- Step 4: GEO Score (0-100) ---
  const geoScore = calculateGeoScore(contentSignals, brandInclusionRate, tavilyResults, practiceProfile);

  // --- Step 5: Generate JSON-LD Schema ---
  const jsonLdSchema = generateJsonLd(title, content, siteUrl);

  // --- Step 6a: Scrape existing FAQs from live HTML ---
  const scrapedFaqPairs = extractExistingFaqs(content);

  // --- Step 6b: Generate Contextual Assets (Gemini) ---
  const contextualAssets = await generateContextualAssets(title, content, layoutMap, practiceProfile);

  // --- Step 6c: Prefer scraped FAQ pairs over Gemini-generated ones ---
  if (contextualAssets && scrapedFaqPairs.length >= 2) {
    contextualAssets.scraped_faq_pairs = scrapedFaqPairs;
    contextualAssets.faq_html = scrapedFaqPairs
      .map(p => `<h3>${p.q}</h3><p>${p.a}</p>`)
      .join('\n');
    console.log(`  [GEO] Using ${scrapedFaqPairs.length} scraped FAQ pairs for post ${id}`);
  } else if (contextualAssets && scrapedFaqPairs.length > 0) {
    // Keep partial scraped pairs for reference even if < 2 (Gemini FAQ still used)
    contextualAssets.scraped_faq_pairs = scrapedFaqPairs;
  }

  // --- Step 7: Build Specific Recommendations (Granular Scoring) ---
  const recommendations = generateRecommendations(contentSignals, brandInclusionRate, geoScore, practiceProfile, layoutMap);

  return {
    id,
    data: {
      title,
      geo_score: geoScore,
      brand_inclusion_rate: brandInclusionRate,
      json_ld_schema: jsonLdSchema,
      contextual_assets: contextualAssets,
      recommendations,
      content_signals: contentSignals,
      layout_map: layoutMap,
      ai_landscape: tavilyResults.slice(0, 3).map(r => ({
        title: r.title,
        url: r.url,
        relevance: r.score ? Math.round(r.score * 100) : null
      }))
    }
  };
}

module.exports = { analyzePost, generateContextualAssets };
