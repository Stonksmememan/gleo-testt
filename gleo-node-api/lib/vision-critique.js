const { GoogleGenAI } = require('@google/genai');

const ai = new GoogleGenAI({
  apiKey: process.env.GOOGLE_GENAI_API_KEY,
});

const GENERIC_SLOP = [
  /awaken your senses/i,
  /finest artisanal/i,
  /elevate your/i,
  /crafted with passion/i,
  /why choose us/i,
  /experience the/i,
];

/**
 * Capture a full-page screenshot of a public URL (Playwright optional).
 * @returns {Promise<{ screenshotBase64: string|null, captureError: string|null }>}
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
 * Multimodal critique: preserve site vibe, flag AI slop, suggest minimal follow-up fixes.
 */
async function critiqueWithGemini({ screenshotBase64, pageUrl, postTitle, captureError }) {
  const allowedFixes = [
    'readability',
    'formatting',
    'structure',
    'opening_summary',
    'image_alt_text',
  ];

  const textPrompt = `You are reviewing a WordPress page after automated GEO (Generative Engine Optimization) fixes.

Page URL: ${pageUrl}
Post title: ${postTitle || 'Unknown'}

Goals:
- The page should still feel like the owner's site, not generic "AI slop"
- Preserve existing branding, colors, and layout rhythm
- Prefer minimal follow-up changes

${captureError ? `Screenshot unavailable (${captureError}). Base your review on URL/title only and be conservative.` : 'A screenshot of the live page is attached.'}

Evaluate TWO things:

1. CONTENT QUALITY: Check if Gleo-injected blocks (FAQ accordions, stats callouts, expert quotes) fit the page. Flag any that look out of place or "AI slop"-like.

2. FULL PAGE VISUAL QUALITY: Look at the overall page appearance — not just the injected blocks. Consider:
   - Are there enough images, or is it a wall of text?
   - Is the typography readable and appropriately sized?
   - Does the spacing feel comfortable or cramped?
   - Do colors look cohesive and professional?
   Set recommend_design_polish=true if the full page visual experience is poor (score < 7).
   Set visual_score to reflect the WHOLE page appearance (1=terrible, 10=excellent).

For suggested_palette: suggest colors that harmonize with the EXISTING site branding.
Do NOT recommend changing logos, fonts, or page builder layouts.

Return strict JSON with these fields.`;

  const parts = [{ text: textPrompt }];
  if (screenshotBase64) {
    parts.unshift({
      inlineData: {
        mimeType: 'image/jpeg',
        data: screenshotBase64,
      },
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
            passed: { type: 'BOOLEAN' },
            summary: { type: 'STRING' },
            issues: { type: 'ARRAY', items: { type: 'STRING' } },
            follow_up_fix_types: { type: 'ARRAY', items: { type: 'STRING' } },
            design_issues: { type: 'ARRAY', items: { type: 'STRING' } },
            recommend_design_polish: { type: 'BOOLEAN' },
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
          },
          required: ['visual_score', 'passed', 'summary', 'issues', 'follow_up_fix_types', 'design_issues', 'recommend_design_polish', 'suggested_palette'],
        },
      },
    });

    const parsed = JSON.parse(response.text);
    const follow = (parsed.follow_up_fix_types || []).filter((t) => allowedFixes.includes(t));
    const palette = parsed.suggested_palette || {};
    return {
      visual_score: Math.max(1, Math.min(10, Math.round(parsed.visual_score || 5))),
      passed: Boolean(parsed.passed),
      summary: parsed.summary || '',
      issues: Array.isArray(parsed.issues) ? parsed.issues : [],
      follow_up_fix_types: follow,
      slop_detected: GENERIC_SLOP.some((re) => re.test(parsed.summary || '')),
      design_issues: Array.isArray(parsed.design_issues) ? parsed.design_issues : [],
      recommend_design_polish: Boolean(parsed.recommend_design_polish),
      suggested_palette: {
        accent:  palette.accent  || '',
        text:    palette.text    || '',
        muted:   palette.muted   || '',
        card:    palette.card    || '',
        surface: palette.surface || '',
        border:  palette.border  || '',
      },
    };
  } catch (err) {
    console.error('[Vision] Gemini critique failed:', err.message);
    return {
      visual_score: null,
      passed: true,
      summary: 'Visual review skipped (AI unavailable). Your GEO fixes were still applied.',
      issues: [],
      follow_up_fix_types: [],
      slop_detected: false,
      design_issues: [],
      recommend_design_polish: false,
      suggested_palette: { accent: '', text: '', muted: '', card: '', surface: '', border: '' },
      critique_error: err.message,
    };
  }
}

/**
 * @param {{ page_url: string, post_title?: string }} input
 */
async function runVisionCritique(input) {
  const pageUrl = input.page_url;
  if (!pageUrl) {
    throw new Error('page_url is required');
  }

  const { screenshotBase64, captureError } = await captureScreenshot(pageUrl);
  const critique = await critiqueWithGemini({
    screenshotBase64,
    pageUrl,
    postTitle: input.post_title || '',
    captureError,
  });

  return {
    screenshot_captured: Boolean(screenshotBase64),
    capture_error: captureError,
    ...critique,
  };
}

module.exports = { runVisionCritique };
