const { GoogleGenAI } = require('@google/genai');

const ai = new GoogleGenAI({
  apiKey: process.env.GOOGLE_GENAI_API_KEY,
});

// ── Screenshot helper ────────────────────────────────────────────────────────

async function captureScreenshot(pageUrl) {
  let playwright;
  try {
    playwright = require('playwright');
  } catch (e) {
    return {
      screenshotBase64: null,
      captureError: 'Playwright is not installed. Run: cd gleo-node-api && npm install && npx playwright install chromium',
    };
  }
  let browser;
  try {
    browser = await playwright.chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(pageUrl, { waitUntil: 'networkidle', timeout: 45000 });
    await page.waitForTimeout(1200);
    const buffer = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 72 });
    return { screenshotBase64: buffer.toString('base64'), captureError: null };
  } catch (err) {
    return { screenshotBase64: null, captureError: err.message };
  } finally {
    if (browser) await browser.close();
  }
}

// ── Unsplash helper ──────────────────────────────────────────────────────────

async function searchUnsplash(query) {
  const accessKey = process.env.UNSPLASH_ACCESS_KEY;
  if (!accessKey) return null;
  try {
    const url = `https://api.unsplash.com/search/photos?query=${encodeURIComponent(query)}&per_page=3&orientation=landscape&client_id=${accessKey}`;
    const res = await fetch(url, { headers: { 'Accept-Version': 'v1' } });
    if (!res.ok) return null;
    const data = await res.json();
    const photo = data.results?.[0];
    if (!photo) return null;
    return {
      url: photo.urls?.regular || photo.urls?.full || '',
      alt: photo.alt_description || query,
      credit: `Photo by ${photo.user?.name || 'Unsplash'} on Unsplash`,
      photographer_name: photo.user?.name || '',
      photographer_url: photo.user?.links?.html || '',
    };
  } catch (err) {
    console.error('[UI Optimizer] Unsplash search failed:', err.message);
    return null;
  }
}

// ── Section detection ────────────────────────────────────────────────────────

/**
 * Build a structured list of page sections from the layout_map (from GEO scan).
 * This gives Gemini a grounded understanding of what sections exist.
 */
function buildSectionContext(layoutMap) {
  if (!layoutMap || !Array.isArray(layoutMap.sections) || layoutMap.sections.length === 0) {
    return 'Section data not available — analyze visible content structure from screenshot.';
  }
  return layoutMap.sections
    .map((s, i) => `  ${i + 1}. "${s.label}" (type: ${s.type || 'content'}, id: ${s.id || `sec_${i + 1}`})`)
    .join('\n');
}

// ── Gemini analysis ──────────────────────────────────────────────────────────

/**
 * Ask Gemini to analyze the page for UI optimization opportunities.
 * Color is already chosen — focus on layout, typography, components, and hierarchy.
 */
async function analyzeWithGemini({ screenshotBase64, pageUrl, postTitle, imageCount, captureError, visualStyle, colorTheme, layoutMap }) {
  const sectionContext = buildSectionContext(layoutMap);
  const styleDescriptions = {
    professional: 'clean, trust-building, moderate spacing, conservative heading scale',
    sleek: 'minimalist, large whitespace, elegant refined typography',
    playful: 'friendly, rounded corners, welcoming, vibrant feel',
    bold: 'strong visual hierarchy, large headings, high-contrast CTAs, attention-grabbing sections',
  };
  const styleDesc = styleDescriptions[visualStyle] || styleDescriptions.professional;

  const textPrompt = `You are a senior UX/UI designer evaluating a WordPress website page for a professional redesign.

Page: ${pageUrl}
Title: ${postTitle || 'Unknown'}
Visual style chosen by the user: "${visualStyle}" — ${styleDesc}
Color theme: "${colorTheme}" (colors are already handled — do NOT focus on color recommendations)
Image count: ${typeof imageCount === 'number' ? imageCount : 'unknown'}

${captureError ? `Screenshot unavailable (${captureError}). Base analysis on URL/title/section list only.` : 'A full-page screenshot is attached.'}

Known page sections (from content scan):
${sectionContext}

YOUR GOAL: Recommend structural, layout, and typographic improvements that will make this page feel like it was redesigned by a professional web designer. At least 70% of your analysis should be about layout, spacing, typography, component structure, visual hierarchy, and UX — NOT colors.

Evaluate these dimensions:
1. TYPOGRAPHY: Are headings visually differentiated with clear size hierarchy? Is body text readable (17-18px+, 1.7+ line-height)? Does the font pairing support the "${visualStyle}" aesthetic?
2. LAYOUT: Is content well-contained (680-760px content width ideal)? Are sections visually separated with adequate spacing? Is there consistent visual rhythm?
3. NAVIGATION: Does navigation look modern and professional? Are there spacing/hover state improvements possible?
4. BUTTONS/CTAs: Are call-to-action buttons visually prominent, well-sized (min 44px height), and compelling?
5. FORMS: If forms exist, do they look professional with clear labels and proper input styling?
6. SECTIONS/COMPONENTS: Which sections would benefit from structural enhancement (hero treatment, card grid, testimonial styling, etc.)?
7. VISUAL HIERARCHY: Does the page guide the eye through content effectively?
8. MOBILE: Any obvious mobile usability issues?

For component_enhancements: Only recommend enhancements for sections that actually exist on the page and would genuinely benefit. Do NOT recommend random content. Return section_id from the known sections list where possible.

For issues: Focus on layout/typography/hierarchy problems, not color.
For image_plan: Only add_hero if image_count === 0; always include style_existing for image polish.`;

  const parts = [{ text: textPrompt }];
  if (screenshotBase64) {
    parts.unshift({
      inlineData: { mimeType: 'image/jpeg', data: screenshotBase64 },
    });
  }

  try {
    const response = await ai.models.generateContent({
      model: 'gemini-2.5-flash',
      contents: [{ role: 'user', parts }],
      config: {
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            visual_score: { type: 'NUMBER' },
            issues: { type: 'ARRAY', items: { type: 'STRING' } },
            optimization_summary: { type: 'ARRAY', items: { type: 'STRING' } },
            typography: {
              type: 'OBJECT',
              properties: {
                body_size:      { type: 'STRING' },
                line_height:    { type: 'STRING' },
                heading_weight: { type: 'STRING' },
                needs_upgrade:  { type: 'BOOLEAN' },
              },
            },
            spacing: {
              type: 'OBJECT',
              properties: {
                content_max_width:  { type: 'STRING' },
                section_gap:        { type: 'STRING' },
                image_margin:       { type: 'STRING' },
                needs_more_space:   { type: 'BOOLEAN' },
              },
            },
            component_enhancements: {
              type: 'ARRAY',
              items: {
                type: 'OBJECT',
                properties: {
                  section_id:    { type: 'STRING' },
                  heading_label: { type: 'STRING' },
                  type:          { type: 'STRING' },
                  confidence:    { type: 'NUMBER' },
                  rationale:     { type: 'STRING' },
                  safe_to_wrap:  { type: 'BOOLEAN' },
                },
              },
            },
            image_plan: {
              type: 'ARRAY',
              items: {
                type: 'OBJECT',
                properties: {
                  action:       { type: 'STRING' },
                  search_query: { type: 'STRING' },
                  placement:    { type: 'STRING' },
                },
              },
            },
            suggested_palette: {
              type: 'OBJECT',
              properties: {
                accent:  { type: 'STRING' },
                text:    { type: 'STRING' },
                muted:   { type: 'STRING' },
                card:    { type: 'STRING' },
                surface: { type: 'STRING' },
                border:  { type: 'STRING' },
              },
            },
            recommend_page_wide: { type: 'BOOLEAN' },
          },
          required: ['visual_score', 'issues', 'optimization_summary', 'typography', 'spacing', 'component_enhancements', 'image_plan', 'recommend_page_wide'],
        },
      },
    });

    const parsed = JSON.parse(response.text);

    // Validate and clamp numeric values
    const score = Math.max(1, Math.min(10, Math.round(parsed.visual_score || 5)));

    // Filter component enhancements to known valid types and reasonable confidence
    const validTypes = ['hero_layout', 'service_cards', 'testimonial_layout', 'faq_accordion', 'cta_banner', 'feature_grid', 'stats_section', 'trust_elements'];
    const enhancements = (Array.isArray(parsed.component_enhancements) ? parsed.component_enhancements : [])
      .filter(e => validTypes.includes(e.type) && (e.confidence || 0) >= 0.5 && e.heading_label)
      .slice(0, 8);

    return {
      visual_score:           score,
      issues:                 Array.isArray(parsed.issues) ? parsed.issues.slice(0, 6) : [],
      optimization_summary:   Array.isArray(parsed.optimization_summary) ? parsed.optimization_summary.slice(0, 8) : [],
      typography:             parsed.typography || {},
      spacing:                parsed.spacing || {},
      component_enhancements: enhancements,
      image_plan:             Array.isArray(parsed.image_plan) ? parsed.image_plan : [],
      suggested_palette:      parsed.suggested_palette || {},
      recommend_page_wide:    Boolean(parsed.recommend_page_wide),
    };
  } catch (err) {
    console.error('[UI Optimizer] Gemini analysis failed:', err.message);
    return {
      visual_score:           null,
      issues:                 [],
      optimization_summary:   ['Typography and spacing will be improved to match your selected style.', 'Navigation and button styles will be modernized.', 'Forms and cards will receive professional styling.'],
      typography:             {},
      spacing:                {},
      component_enhancements: [],
      image_plan:             [{ action: 'style_existing', placement: 'all' }],
      suggested_palette:      {},
      recommend_page_wide:    true,
      analysis_error:         err.message,
    };
  }
}

// ── Main entry point ─────────────────────────────────────────────────────────

/**
 * Run the full UI optimization analysis.
 *
 * @param {object} input
 * @param {string} input.page_url
 * @param {string} [input.post_title]
 * @param {number} [input.image_count]
 * @param {string} [input.visual_style]  professional|sleek|playful|bold
 * @param {string} [input.color_theme]   green|blue|purple
 * @param {object} [input.layout_map]    From GEO scan analyzePageLayout()
 */
async function runUiOptimization(input) {
  const {
    page_url,
    post_title,
    image_count,
    visual_style = 'professional',
    color_theme  = 'blue',
    layout_map   = null,
  } = input;

  if (!page_url) throw new Error('page_url is required');

  const { screenshotBase64, captureError } = await captureScreenshot(page_url);

  const analysis = await analyzeWithGemini({
    screenshotBase64,
    pageUrl:      page_url,
    postTitle:    post_title || '',
    imageCount:   typeof image_count === 'number' ? image_count : 0,
    captureError,
    visualStyle:  visual_style,
    colorTheme:   color_theme,
    layoutMap:    layout_map,
  });

  // Fetch Unsplash hero candidate if add_hero is recommended
  let unsplash_photo = null;
  const heroStep = (analysis.image_plan || []).find(s => s.action === 'add_hero' && s.search_query);
  if (heroStep) {
    unsplash_photo = await searchUnsplash(heroStep.search_query);
  }

  return {
    screenshot_captured: Boolean(screenshotBase64),
    capture_error:       captureError,
    visual_style,
    color_theme,
    unsplash_photo,
    ...analysis,
  };
}

module.exports = { runUiOptimization };
