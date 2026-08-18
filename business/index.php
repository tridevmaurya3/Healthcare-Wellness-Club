<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
$status = business_db_status();
$isReady = (bool)$status['connected'] && (string)$status['schema_version'] === '2.0-world-ready';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Business OS Foundation - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/business.css">
</head>
<body>
  <header class="biz-topbar">
    <div class="biz-topbar-inner">
      <a class="biz-brand" href="../index.html">
        <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
        <span><strong>Healthcare Wellness Club</strong><small>Business OS • World-Ready Foundation</small></span>
      </a>
      <a class="biz-back" href="../index.html">← Public Website</a>
    </div>
  </header>

  <main class="biz-shell">
    <section class="biz-hero">
      <div>
        <div class="biz-kicker">World-Ready Business Architecture</div>
        <h1>One source of business truth, with live calculations instead of duplicate formula sheets.</h1>
        <p>The system is prepared for multiple organizations, clubs, countries, currencies and data sources. Operational records become the source of truth; the first six workbook sheets are rebuilt as live derived reports.</p>
      </div>
      <div class="biz-status <?= $isReady ? 'ready' : '' ?>">
        <i></i><?= htmlspecialchars($isReady ? 'World-Ready Schema Active' : 'Database Setup Pending', ENT_QUOTES, 'UTF-8') ?>
      </div>
    </section>

    <section class="biz-grid" aria-label="Business system status">
      <article class="biz-card biz-kpi green"><small>Database</small><strong><?= $status['connected'] ? 'Online' : 'Pending' ?></strong><span><?= htmlspecialchars((string)$status['database'], ENT_QUOTES, 'UTF-8') ?></span></article>
      <article class="biz-card biz-kpi blue"><small>Schema</small><strong><?= htmlspecialchars((string)$status['schema_version'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= (int)$status['table_count'] ?> database tables</span></article>
      <article class="biz-card biz-kpi gold"><small>Organizations / Clubs</small><strong><?= (int)$status['organization_count'] ?> / <?= (int)$status['club_count'] ?></strong><span>Multi-tenant structure</span></article>
      <article class="biz-card biz-kpi"><small>Derived Reports</small><strong><?= (int)$status['derived_report_count'] ?></strong><span>Workbook sheets 1-6 become live calculations</span></article>

      <article class="biz-card biz-section">
        <h2>How the workbook will work inside Business OS</h2>
        <p>The workbook is no longer treated as fourteen isolated sheets. Source data and calculated reporting are intentionally separated.</p>
        <div class="biz-module-grid">
          <div class="biz-module"><b>Source Data Layer</b><span>Google Forms, website forms, manual entry, Excel and future APIs enter through one traceable ingestion layer.</span></div>
          <div class="biz-module"><b>Members & Sponsor Network</b><span>One member identity can connect UMS, sponsor/upline, orders, VP, renewals and business history.</span></div>
          <div class="biz-module"><b>UMS Lifecycle</b><span>New UMS, active duration, expiry, renewal due and renewal history are generated from real records.</span></div>
          <div class="biz-module"><b>Orders, VP & Income</b><span>Operational transactions become reusable facts for all dashboards and reports.</span></div>
          <div class="biz-module"><b>Six Derived Workbook Reports</b><span>Master Tracking, SP House, Name Wise, Master Business, UMS Renewal and Active Duration are calculated live.</span></div>
          <div class="biz-module"><b>Global Tenant Safety</b><span>Every business record is organization-scoped so future clubs/countries can stay isolated.</span></div>
        </div>
      </article>

      <aside class="biz-card biz-side">
        <h2>Architecture status</h2>
        <p>The foundation is ready for local development now and cloud deployment later.</p>
        <div class="biz-steps">
          <div class="biz-step"><div class="biz-step-num">01</div><div><b>Activate MySQL schema</b><span>Run the same world-ready schema locally in XAMPP.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">02</div><div><b>Import source sheets only</b><span>Preserve original Google Form/Excel rows before normalization.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">03</div><div><b>Formula analysis</b><span>Translate workbook sheets 1-6 formulas into versioned calculation rules.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">04</div><div><b>Live Business Dashboard</b><span>KPIs, filters, trends, renewals, VP and income from one database.</span></div></div>
        </div>
        <div class="biz-tech"><span>Multi-Tenant</span><span>Multi-Country</span><span>Currency Ready</span><span>Raw Trace</span><span>Derived Reports</span></div>
      </aside>
    </section>

    <div class="biz-note"><strong>Current status:</strong> <?= htmlspecialchars((string)$status['message'], ENT_QUOTES, 'UTF-8') ?> Raw source records: <?= (int)$status['raw_record_count'] ?> • Members: <?= (int)$status['member_count'] ?> • Calculation rules: <?= (int)$status['calculation_rule_count'] ?>. No personal workbook data has been imported yet.</div>
  </main>
</body>
</html>
