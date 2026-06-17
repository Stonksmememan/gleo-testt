require('dotenv').config();
const express = require('express');
const cors = require('cors');
const crypto = require('crypto');
const clients = require('./config/clients');
const { analyzePost } = require('./lib/geo-analyzer');
const { runVisionCritique } = require('./lib/vision-critique');
const { runAppearanceAnalysis } = require('./lib/appearance-enhancer');
const analyticsRoutes = require('./routes/analytics');
const { initSOVCron } = require('./lib/sov-simulator');

const app = express();
const port = process.env.PORT || 8765;
// #region agent log
const debugLog = (hypothesisId, message, data = {}) => {
  if (typeof fetch !== 'function') return;
  fetch('http://127.0.0.1:7898/ingest/7384af93-f840-4225-9885-a251d9e6d233',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'570b9f'},body:JSON.stringify({sessionId:'570b9f',runId:'pre-fix',hypothesisId,location:'gleo-node-api/index.js',message,data,timestamp:Date.now()})}).catch(()=>{});
};
// #endregion

// CORS allow-list (use ALLOWED_ORIGINS env, comma-separated). Defaults are dev-friendly.
const allowedOrigins = (process.env.ALLOWED_ORIGINS || '')
  .split(',')
  .map(s => s.trim())
  .filter(Boolean);

app.use(cors({
  origin: (origin, cb) => {
    // Allow same-origin / server-to-server requests with no Origin header (e.g. WordPress wp_remote_post)
    if (!origin) return cb(null, true);
    if (allowedOrigins.length === 0) return cb(null, true); // dev mode: allow all
    if (allowedOrigins.includes(origin)) return cb(null, true);
    return cb(new Error(`CORS: origin ${origin} not allowed`));
  },
  credentials: true,
}));

// #region agent log
app.use((req, res, next) => {
  debugLog('A,B,C', 'Node API received inbound request', {
    method: req.method,
    path: req.path,
    host: req.headers.host || '',
    origin: req.headers.origin || '',
  });
  next();
});
// #endregion

// Need access to the raw payload body for HMAC calculation
app.use(express.json({
  limit: '50mb',
  verify: (req, res, buf) => {
    req.rawBody = buf;
  }
}));

// Middleware to intercept and verify incoming requests via X-Gleo-Signature
const verifySignature = (req, res, next) => {
  const clientId = req.headers['x-gleo-client-id'];
  const signature = req.headers['x-gleo-signature'];

  if (!clientId || !signature) {
    console.log(`[Reject] Missing Client ID or Signature from request.`);
    return res.status(401).json({ error: 'Missing Client ID or Signature' });
  }

  const secret = clients[clientId];
  if (!secret) {
    console.log(`[Reject] Unknown Client ID: ${clientId}`);
    return res.status(401).json({ error: 'Invalid Client ID. Hint: Use TEST_CLIENT_ID and TEST_SECRET_KEY for now.' });
  }

  // Calculate HMAC using the raw body
  const hmac = crypto.createHmac('sha256', secret);
  hmac.update(req.rawBody || '');
  const expectedSignature = hmac.digest('hex');

  // Use timingSafeEqual to prevent timing attacks
  try {
    const signatureBuffer = Buffer.from(signature, 'hex');
    const expectedBuffer = Buffer.from(expectedSignature, 'hex');
    
    if (signatureBuffer.length !== expectedBuffer.length || !crypto.timingSafeEqual(signatureBuffer, expectedBuffer)) {
      console.log(`[Reject] Invalid HMAC signature for Client ID: ${clientId}`);
      return res.status(401).json({ error: 'Invalid Signature' });
    }
  } catch (e) {
    console.log(`[Reject] Invalid Signature Format for Client ID: ${clientId}`);
    return res.status(401).json({ error: 'Invalid Signature Format' });
  }

  next();
};

app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', message: 'Gleo Node API is running' });
});

// Middleware not required for bot hits to ensure high speed, but verifySignature can be added if needed
app.use('/v1/analytics', analyticsRoutes);

app.post('/api/process', verifySignature, (req, res) => {
  res.json({ status: 'success', message: 'Signature verified successfully!', data: req.body });
});

app.post('/v1/optimize/critique', verifySignature, async (req, res) => {
  const { page_url, post_title } = req.body || {};
  if (!page_url) {
    return res.status(400).json({ error: 'Missing page_url' });
  }
  try {
    const result = await runVisionCritique({ page_url, post_title });
    return res.json({ status: 'ok', data: result });
  } catch (err) {
    console.error('[Vision] Critique error:', err.message);
    return res.status(500).json({ error: err.message });
  }
});

app.post('/v1/optimize/appearance', verifySignature, async (req, res) => {
  const { page_url, post_title, image_count } = req.body || {};
  if (!page_url) {
    return res.status(400).json({ error: 'Missing page_url' });
  }
  try {
    const result = await runAppearanceAnalysis({ page_url, post_title, image_count });
    return res.json({ status: 'ok', data: result });
  } catch (err) {
    console.error('[Appearance] Analysis error:', err.message);
    return res.status(500).json({ error: err.message });
  }
});

app.post('/v1/analyze/start', verifySignature, (req, res) => {
  const { batch_id, webhook, posts, site_url, practice_profile } = req.body;
  
  if (!webhook || !posts) {
    return res.status(400).json({ error: 'Missing webhook or posts array' });
  }

  // Immediately respond 202 to free up the WordPress PHP process
  res.status(202).json({ status: 'accepted', message: 'Batch queued for GEO analysis' });

  // Process asynchronously with real Tavily analysis
  (async () => {
    console.log(`\n[GEO] Starting batch ${batch_id} — ${posts.length} posts to analyze...`);
    
    for (const post of posts) {
      let report;
      try {
        report = await analyzePost(post, site_url || '', practice_profile || null);
      } catch (err) {
        console.error(`  [GEO] Failed to analyze post ${post.id}:`, err.message);
        report = {
          id: post.id,
          data: {
            title: post.title,
            geo_score: 0,
            brand_inclusion_rate: 0,
            answer_capsule: 'Analysis failed for this post.',
            json_ld_schema: null,
            recommendations: [{
              priority: 'critical',
              area: 'Error',
              message: `Analysis failed: ${err.message}`
            }],
            content_signals: {},
            ai_landscape: []
          }
        };
      }

      // Send webhook PER POST so WordPress naturally updates the progress bar
      try {
        await fetch(webhook, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ batch_id, results: [report] })
        });
        console.log(`  [GEO] Webhook delivered for post ${post.id}`);
      } catch (e) {
        console.error(`  [GEO] Webhook delivery failed for post ${post.id}:`, e.message);
      }
    }

    console.log(`[GEO] Batch ${batch_id} complete.\n`);
  })();
});

app.listen(port, () => {
  // #region agent log
  debugLog('A,C', 'Node API listener started', {
    port: String(port),
    env_port_set: Boolean(process.env.PORT),
    allowed_origins_count: allowedOrigins.length,
  });
  // #endregion
  console.log(`Gleo Node API listening on port ${port}`);
  initSOVCron();
});

