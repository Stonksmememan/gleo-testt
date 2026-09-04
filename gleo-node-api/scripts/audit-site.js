#!/usr/bin/env node
/**
 * Calibration harness for the site auditor.
 *
 * Runs lib/site-audit.js directly, bypassing HTTP and HMAC, so a batch of real
 * sites can be scored without standing anything up. Use it to check score
 * distribution before trusting the number in front of a customer.
 *
 * Usage:
 *   node scripts/audit-site.js https://example.com
 *   node scripts/audit-site.js https://example.com --pages 8 --render
 *   node scripts/audit-site.js --file sites.txt --csv results.csv
 *   node scripts/audit-site.js https://example.com --json > report.json
 *
 * Options:
 *   --file <path>   Newline-separated URLs to audit in sequence
 *   --pages <n>     Max pages per site (default 12)
 *   --render        Use Playwright for pages that look JS-rendered
 *   --json          Emit raw JSON instead of a readable summary
 *   --csv <path>    Append one summary row per site to a CSV
 *   --out <dir>     Write the full JSON report per site into a directory
 */

require('dotenv').config();

const fs = require('fs');
const path = require('path');

const { auditSite } = require('../lib/site-audit');

function parseArgs(argv) {
  const options = { urls: [], pages: 12, render: false, json: false, csv: null, out: null, file: null };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    switch (arg) {
      case '--pages': options.pages = parseInt(argv[++i], 10) || 12; break;
      case '--render': options.render = true; break;
      case '--json': options.json = true; break;
      case '--csv': options.csv = argv[++i]; break;
      case '--out': options.out = argv[++i]; break;
      case '--file': options.file = argv[++i]; break;
      default:
        if (arg.startsWith('--')) throw new Error(`Unknown option: ${arg}`);
        options.urls.push(arg);
    }
  }

  if (options.file) {
    const contents = fs.readFileSync(options.file, 'utf8');
    for (const line of contents.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (trimmed && !trimmed.startsWith('#')) options.urls.push(trimmed);
    }
  }

  if (options.urls.length === 0) {
    throw new Error('No URLs given. Pass a URL or --file <path>.');
  }

  return options;
}

function bar(value, width = 24) {
  const filled = Math.round((Math.max(0, Math.min(100, value)) / 100) * width);
  return '█'.repeat(filled) + '·'.repeat(width - filled);
}

function printReport(report) {
  const { site, score, practice, blockers, pillars, findings, pages, crawl, meta } = report;

  console.log('');
  console.log(`${site.host}`);
  console.log(`${'─'.repeat(64)}`);
  console.log(`GEO score        ${String(score.site_score).padStart(3)} / 100  ${bar(score.site_score)}`);
  console.log(`Weighting        ${score.weighting}${practice.practice_type ? ` (${practice.practice_type}, ${practice.confidence} confidence: ${practice.evidence})` : ''}`);
  console.log(`Pages crawled    ${crawl.pages_crawled}${crawl.pages_failed ? ` (${crawl.pages_failed} failed)` : ''}${crawl.rendered_pages ? `, ${crawl.rendered_pages} rendered` : ''}`);
  console.log(`Site files       llms.txt ${crawl.llms_txt_found ? 'yes' : 'no'} · robots.txt ${crawl.robots_txt_found ? 'yes' : 'no'} · sitemap ${crawl.sitemap_url ? 'yes' : 'no'}`);
  console.log(`Duration         ${(meta.duration_ms / 1000).toFixed(1)}s`);

  if (blockers.length > 0) {
    console.log('');
    console.log('BLOCKERS');
    for (const blocker of blockers) {
      console.log(`  [${blocker.severity}] ${blocker.label}`);
      console.log(`      ${blocker.detail}`);
    }
  }

  console.log('');
  console.log('PILLARS');
  for (const pillar of pillars) {
    console.log(`  ${pillar.label.padEnd(28)} ${String(pillar.health).padStart(3)}%  ${bar(pillar.health, 16)}  ${pillar.checks_failing}/${pillar.checks_total} failing`);
  }

  const failing = findings.filter(f => f.status !== 'pass');
  console.log('');
  console.log(`TOP OPPORTUNITIES (${failing.length} of ${findings.length} checks failing)`);
  for (const finding of failing.slice(0, 12)) {
    const scopeLabel = finding.scope === 'site'
      ? 'site-wide'
      : `${finding.pages_failing}/${finding.pages_total} pages`;
    console.log(`  -${String(finding.points_lost).padStart(3)} pts  ${finding.label} (${scopeLabel}, tier ${finding.tier})`);
    console.log(`            ${finding.detail}`);
  }

  console.log('');
  console.log('PAGES');
  for (const page of [...pages].sort((a, b) => b.score - a.score)) {
    const builder = page.builder_detected ? ` [${page.builder_detected}]` : '';
    console.log(`  ${String(page.score).padStart(3)}  ${page.type.padEnd(9)} ${page.word_count.toString().padStart(5)}w${builder}  ${page.url}`);
  }
  console.log('');
}

const CSV_COLUMNS = [
  'host', 'site_score', 'weighting', 'practice_type', 'pages_crawled',
  'blockers', 'critical_blockers', 'llms_txt', 'sitemap', 'ai_crawlers_blocked',
  'checks_failing', 'top_opportunity', 'duration_ms',
];

function csvRow(report) {
  const failing = report.findings.filter(f => f.status !== 'pass');
  const values = [
    report.site.host,
    report.score.site_score,
    report.score.weighting,
    report.practice.practice_type || '',
    report.crawl.pages_crawled,
    report.blockers.length,
    report.blockers.filter(b => b.severity === 'critical').length,
    report.crawl.llms_txt_found ? 'yes' : 'no',
    report.crawl.sitemap_url ? 'yes' : 'no',
    report.crawl.ai_crawlers_blocked.join('|'),
    failing.length,
    failing[0] ? failing[0].label : '',
    report.meta.duration_ms,
  ];
  return values.map(v => {
    const s = String(v ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }).join(',');
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const reports = [];
  const failures = [];

  if (options.out) fs.mkdirSync(options.out, { recursive: true });

  for (const url of options.urls) {
    if (!options.json) process.stderr.write(`Auditing ${url} ... `);
    try {
      const report = await auditSite(url, {
        maxPages: options.pages,
        allowRender: options.render,
      });
      reports.push(report);
      if (!options.json) process.stderr.write(`${report.score.site_score}/100\n`);

      if (options.out) {
        const safeName = report.site.host.replace(/[^a-z0-9.-]/gi, '_');
        fs.writeFileSync(path.join(options.out, `${safeName}.json`), JSON.stringify(report, null, 2));
      }
    } catch (err) {
      failures.push({ url, error: err.message });
      if (!options.json) process.stderr.write(`FAILED (${err.message})\n`);
    }
  }

  if (options.json) {
    console.log(JSON.stringify(reports.length === 1 ? reports[0] : reports, null, 2));
  } else if (reports.length === 1) {
    printReport(reports[0]);
  } else {
    console.log('');
    console.log(`${'HOST'.padEnd(34)} ${'SCORE'.padStart(5)}  ${'WEIGHTING'.padEnd(11)} ${'PAGES'.padStart(5)}  BLOCKERS`);
    console.log('─'.repeat(78));
    for (const report of [...reports].sort((a, b) => a.score.site_score - b.score.site_score)) {
      console.log(
        `${report.site.host.slice(0, 33).padEnd(34)} ${String(report.score.site_score).padStart(5)}  ` +
        `${report.score.weighting.padEnd(11)} ${String(report.crawl.pages_crawled).padStart(5)}  ${report.blockers.length}`
      );
    }

    const scores = reports.map(r => r.score.site_score).sort((a, b) => a - b);
    if (scores.length > 0) {
      const mean = Math.round(scores.reduce((a, b) => a + b, 0) / scores.length);
      const median = scores[Math.floor(scores.length / 2)];
      console.log('─'.repeat(78));
      console.log(`n=${scores.length}  min=${scores[0]}  median=${median}  mean=${mean}  max=${scores[scores.length - 1]}`);
    }
    console.log('');
  }

  if (options.csv) {
    const exists = fs.existsSync(options.csv);
    const lines = reports.map(csvRow);
    fs.appendFileSync(options.csv, (exists ? '' : CSV_COLUMNS.join(',') + '\n') + lines.join('\n') + '\n');
    process.stderr.write(`Wrote ${lines.length} row(s) to ${options.csv}\n`);
  }

  if (failures.length > 0) {
    process.stderr.write(`\n${failures.length} site(s) failed:\n`);
    for (const failure of failures) process.stderr.write(`  ${failure.url}: ${failure.error}\n`);
  }

  process.exit(reports.length > 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
