<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
$status = business_db_status();
$isReady = (bool)$status['connected'] && (int)$status['table_count'] >= 9;
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
        <span><strong>Healthcare Wellness Club</strong><small>Business OS • Foundation</small></span>
      </a>
      <a class="biz-back" href="../index.html">← Public Website</a>
    </div>
  </header>

  <main class="biz-shell">
    <section class="biz-hero">
      <div>
        <div class="biz-kicker">Business System Foundation</div>
        <h1>Excel tracking is being converted into a connected Business OS.</h1>
        <p>This foundation keeps the original Excel source traceable while preparing normalized data for members, UMS, orders, volume points, renewals, income, royalty and future product integration.</p>
      </div>
      <div class="biz-status <?= $isReady ? 'ready' : '' ?>">
        <i></i><?= htmlspecialchars($isReady ? 'Database Ready' : 'Database Setup Pending', ENT_QUOTES, 'UTF-8') ?>
      </div>
    </section>

    <section class="biz-grid" aria-label="Business system status">
      <article class="biz-card biz-kpi green"><small>Database</small><strong><?= $status['connected'] ? 'Online' : 'Pending' ?></strong><span><?= htmlspecialchars((string)$status['database'], ENT_QUOTES, 'UTF-8') ?></span></article>
      <article class="biz-card biz-kpi blue"><small>Core Tables</small><strong><?= (int)$status['table_count'] ?></strong><span>Normalized business data structure</span></article>
      <article class="biz-card biz-kpi gold"><small>Excel Imports</small><strong>0</strong><span>Import engine comes in the next phase</span></article>
      <article class="biz-card biz-kpi"><small>Live Records</small><strong>0</strong><span>No workbook data imported yet</span></article>

      <article class="biz-card biz-section">
        <h2>Business modules prepared by this foundation</h2>
        <p>The database is designed so the workbook becomes one connected system instead of separate disconnected sheets.</p>
        <div class="biz-module-grid">
          <div class="biz-module"><b>Members & Sponsor Network</b><span>Single master member identity with sponsor/upline relationships.</span></div>
          <div class="biz-module"><b>UMS Lifecycle</b><span>New UMS, active duration, renewal due dates and renewal history.</span></div>
          <div class="biz-module"><b>Orders & Volume Points</b><span>Regular, First/Second Set and extra customer orders with VP tracking.</span></div>
          <div class="biz-module"><b>Income & Royalty</b><span>Monthly income categories, royalty periods and future analytics.</span></div>
          <div class="biz-module"><b>Excel Staging Layer</b><span>Original sheet/row content can be retained before smart mapping.</span></div>
          <div class="biz-module"><b>Audit & Traceability</b><span>Every future import/change can be tied back to its source and user.</span></div>
        </div>
      </article>

      <aside class="biz-card biz-side">
        <h2>Foundation roadmap</h2>
        <p>We will activate this system in controlled steps.</p>
        <div class="biz-steps">
          <div class="biz-step"><div class="biz-step-num">01</div><div><b>Create MySQL database</b><span>Import the prepared schema in XAMPP/phpMyAdmin.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">02</div><div><b>Excel Import Engine</b><span>Upload Master_Personal_Tracking.xlsx into staging safely.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">03</div><div><b>Sheet Mapping</b><span>Map New UMS, VP, Renewals, Income, Royalty and orders.</span></div></div>
          <div class="biz-step"><div class="biz-step-num">04</div><div><b>Smart Dashboard</b><span>Live KPIs, filters, trends, alerts and reports.</span></div></div>
        </div>
        <div class="biz-tech"><span>PHP 8</span><span>PDO</span><span>MySQL</span><span>UTF-8</span><span>Import Trace</span></div>
      </aside>
    </section>

    <div class="biz-note"><strong>Current status:</strong> <?= htmlspecialchars((string)$status['message'], ENT_QUOTES, 'UTF-8') ?> No personal business data is shown on this page yet.</div>
  </main>
</body>
</html>
