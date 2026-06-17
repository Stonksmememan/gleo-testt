const { GoogleGenAI } = require('@google/genai');

const ai = new GoogleGenAI({
  apiKey: process.env.GOOGLE_GENAI_API_KEY,
});

/**
 * Capture a full-page screenshot (same pattern as vision-critique.js).
 */
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

/**
 * Search Unsplash for a relevant stock photo.
 * Returns null if UNSPLASH_ACCESS_KEY is not set or the request fails.
 */
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
    console.error('[Appearance] Unsplash search failed:', err.message);
    return null;
  }
}

/**
 * Ask Gemini to evaluate the full page visual appearance and generate an improvement plan.
 */
async function analyzeAppearanceWithGemini({ screenshotBase64, pageUrl, postTitle, imageCount, captureError }) {
  const textPrompt = `You are a professional web designer reviewing a WordPress page to improve its visual appearance.

Page URL: ${pageUrl}
Post title: ${postTitle || 'Unknown'}
Current image count on page: ${typeof imageCount === 'number' ? imageCount : 'unknown'}

${captureError ? `Screenshot unavailable (${captureError}). Base your review on the URL and title only, and be conservative.` : 'A screenshot of the live page is attached.'}

Your job is to recommend practical, safe visual improvements that:
- Make the page look more professional and polished
- Work safely on top of any WordPress theme (CSS injection only — no theme file edits)
- Focus on: image quality/presence, typography readability, content spacing, and color harmony

Evaluate the FULL content area of the page:
1. Images: Are there enough contextual images? Are they well-sized and presented?
2. Typography: Is body text well-sized (16–20px) and spaced (line-height 1.6–1.8)?
3. Spacing: Is content comfortably spaced or cramped? Is content width readable (680–760px ideal)?
4. Colors: Do text and accent colors look cohesive and professional?

IMPORTANT rules for image_plan:
- Only include action "add_hero" if image_count === 0 or the page is clearly text-only with NO relevant images
- If suggesting "add_hero", write a very specific Unsplash search_query (e.g. "modern dental clinic waiting room" not just "dental")
- Always include action "style_existing" to apply CSS polish to all images (border-radius, shadow)
- "placement" for add_hero should be "before_first_heading" or "after_first_paragraph"

For typography: suggest CSS values — body_size like "17px", line_height like "1.75", heading_weight like "700"
For spacing: content_max_width like "720px", section_gap like "2rem", image_margin like "1.5rem"
For palette: suggest harmonious hex colors that complement the visible site branding`;

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
            typography: {
              type: 'OBJECT',
              properties: {
                body_size:      { type: 'STRING' },
                line_height:    { type: 'STRING' },
                heading_weight: { type: 'STRING' },
              },
            },
            spacing: {
              type: 'OBJECT',
              properties: {
                content_max_width: { type: 'STRING' },
                section_gap:       { type: 'STRING' },
                image_margin:      { type: 'STRING' },
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
            recommend_page_wide: { type: 'BOOLEAN' },
          },
          required: ['visual_score', 'issues', 'suggested_palette', 'typography', 'spacing', 'image_plan', 'recommend_page_wide'],
        },
      },
    });

    const parsed = JSON.parse(response.text);
    return {
      visual_score:       Math.max(1, Math.min(10, Math.round(parsed.visual_score || 5))),
      issues:             Array.isArray(parsed.issues) ? parsed.issues : [],
      suggested_palette:  parsed.suggested_palette || {},
      typography:         parsed.typography || {},
      spacing:            parsed.spacing || {},
      image_plan:         Array.isArray(parsed.image_plan) ? parsed.image_plan : [],
      recommend_page_wide: Boolean(parsed.recommend_page_wide),
    };
  } catch (err) {
    console.error('[Appearance] Gemini analysis failed:', err.message);
    return {
      visual_score: null,
      issues: [],
      suggested_palette: {},
      typography: {},
      spacing: {},
      image_plan: [],
      recommend_page_wide: false,
      analysis_error: err.message,
    };
  }
}

/**
 * Main entry point — analyze page appearance and fetch a candidate stock photo if needed.
 * @param {{ page_url: string, post_title?: string, image_count?: number }} input
 */
async function runAppearanceAnalysis(input) {
  const { page_url, post_title, image_count } = input;
  if (!page_url) throw new Error('page_url is required');

  const { screenshotBase64, captureError } = await captureScreenshot(page_url);
  const analysis = await analyzeAppearanceWithGemini({
    screenshotBase64,
    pageUrl: page_url,
    postTitle: post_title || '',
    imageCount: typeof image_count === 'number' ? image_count : 0,
    captureError,
  });

  // If a hero image is recommended, pre-fetch an Unsplash candidate for the user to preview
  let unsplash_photo = null;
  const heroStep = (analysis.image_plan || []).find(s => s.action === 'add_hero' && s.search_query);
  if (heroStep) {
    unsplash_photo = await searchUnsplash(heroStep.search_query);
  }

  return {
    screenshot_captured: Boolean(screenshotBase64),
    capture_error: captureError,
    unsplash_photo,
    ...analysis,
  };
}

module.exports = { runAppearanceAnalysis };
