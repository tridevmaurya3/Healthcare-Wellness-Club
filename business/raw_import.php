<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/xlsx_reader.php';

$mapping = require __DIR__ . '/config/import_mapping.php';
const RAW_IMPORT_MAX_BYTES = 20 * 1024 * 1024;

function raw_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function raw_json(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('A source row could not be encoded safely.');
    }
    return $json;
}

function raw_context(PDO $pdo): array
{
    $org = $pdo->query("SELECT id, organization_name FROM organizations WHERE organization_code='HWC-001' LIMIT 1")->fetch();
    if (!$org) {
        throw new RuntimeException('Healthcare Wellness Club organization seed is missing.');
    }

    $clubStmt = $pdo->prepare("SELECT id, club_name FROM clubs WHERE organization_id=? AND club_code='GHAZIPUR-001' LIMIT 1");
    $clubStmt->execute([(int)$org['id']]);
    $club = $clubStmt->fetch();
    if (!$club) {
        throw new RuntimeException('Ghazipur club seed is missing.');
    }

    $sourceStmt = $pdo->prepare("SELECT id, source_name FROM data_sources WHERE organization_id=? AND source_code='LEGACY-XLSX' LIMIT 1");
    $sourceStmt->execute([(int)$org['id']]);
    $source = $sourceStmt->fetch();
    if (!$source) {
        throw new RuntimeException('LEGACY-XLSX data source seed is missing.');
    }

    return [
        'organization_id' => (int)$org['id'],
        'organization_name' => (string)$org['organization_name'],
        'club_id' => (int)$club['id'],
        'club_name' => (string)$club['club_name'],
        'data_source_id' => (int)$source['id'],
        'data_source_name' => (string)$source['source_name'],
    ];
}

function validate_source_headers(string $sheetName, array $actualHeaders, array $sheetMapping): void
{
    $mismatches = [];
    foreach (($sheetMapping['columns'] ?? []) as $column => $definition) {
        $expected = trim((string)($definition['header'] ?? ''));
        $actual = trim((string)($actualHeaders[$column] ?? ''));
        if ($expected !== $actual) {
            $mismatches[] = $column . ': expected “' . $expected . '”, found “' . $actual . '”';
        }
    }

    if ($mismatches) {
        throw new RuntimeException(
            'Mapping validation failed for ' . $sheetName . '. ' . implode('; ', array_slice($mismatches, 0, 5))
        );
    }
}

$status = business_db_status();
$error = null;
$success = null;
$result = null;
$history = [];

try {
    $pdo = business_db();
    $context = raw_context($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['confirm_raw_capture'] ?? '') !== 'yes') {
            throw new RuntimeException('Confirm that this step captures raw rows only before continuing.');
        }
        if (!isset($_FILES['workbook']) || !is_array($_FILES['workbook'])) {
            throw new RuntimeException('Choose the XLSX workbook first.');
        }

        $file = $_FILES['workbook'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook is larger than the server upload limit.',
                UPLOAD_ERR_PARTIAL => 'The workbook upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose the XLSX workbook first.',
                default => 'Workbook upload failed. Error code: ' . $uploadError,
            });
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $originalName = basename((string)($file['name'] ?? 'workbook.xlsx'));
        $size = (int)($file['size'] ?? 0);

        if (!is_uploaded_file($tmpPath)) {
            throw new RuntimeException('The workbook upload could not be verified.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Only .xlsx workbooks are accepted.');
        }
        if ($size <= 0 || $size > RAW_IMPORT_MAX_BYTES) {
            throw new RuntimeException('Workbook size must be between 1 byte and 20 MB.');
        }

        $fileHash = hash_file('sha256', $tmpPath);
        if (!$fileHash) {
            throw new RuntimeException('The workbook fingerprint could not be created.');
        }

        $existingStmt = $pdo->prepare(
            "SELECT id, imported_rows, completed_at
             FROM import_batches
             WHERE organization_id=? AND data_source_id=? AND file_sha256=? AND status='completed'
             ORDER BY id DESC LIMIT 1"
        );
        $existingStmt->execute([
            $context['organization_id'],
            $context['data_source_id'],
            $fileHash,
        ]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $result = [
                'duplicate' => true,
                'batch_id' => (int)$existing['id'],
                'captured_rows' => (int)$existing['imported_rows'],
                'skipped_rows' => (int)$existing['imported_rows'],
                'file_hash' => $fileHash,
                'file_name' => $originalName,
            ];
            $success = 'This exact workbook was already captured safely, so no duplicate raw rows were created.';
        } else {
            $reader = new XlsxPreviewReader($tmpPath);
            $sheetIndex = [];
            foreach ($reader->sheets() as $sheet) {
                $sheetIndex[trim((string)$sheet['name'])] = $sheet;
            }

            $sourceSheets = (array)($mapping['source_sheets'] ?? []);
            if (count($sourceSheets) !== 8) {
                throw new RuntimeException('Reviewed mapping must contain exactly 8 operational source sheets.');
            }

            $preparedSheets = [];
            $totalRows = 0;
            foreach ($sourceSheets as $canonicalSheetName => $sheetMapping) {
                if (!isset($sheetIndex[$canonicalSheetName])) {
                    throw new RuntimeException('Required source sheet is missing: ' . $canonicalSheetName);
                }

                $sheet = $sheetIndex[$canonicalSheetName];
                $sheetData = $reader->readSheetRows((string)$sheet['path']);
                validate_source_headers($canonicalSheetName, $sheetData['headers'], $sheetMapping);

                $preparedSheets[$canonicalSheetName] = $sheetData;
                $totalRows += count($sheetData['rows']);
            }

            if ($totalRows === 0) {
                throw new RuntimeException('No operational rows were found in the workbook.');
            }

            $pdo->beginTransaction();
            try {
                $batchInsert = $pdo->prepare(
                    "INSERT INTO import_batches
                     (organization_id, club_id, data_source_id, original_file_name, file_sha256,
                      import_type, status, total_rows, imported_rows, skipped_rows, failed_rows,
                      started_at, notes)
                     VALUES (?, ?, ?, ?, ?, 'excel_raw_capture', 'processing', ?, 0, 0, 0, NOW(), ?)"
                );
                $batchInsert->execute([
                    $context['organization_id'],
                    $context['club_id'],
                    $context['data_source_id'],
                    $originalName,
                    $fileHash,
                    $totalRows,
                    'Raw source capture only. Mapping version: ' . (string)($mapping['version'] ?? 'unknown'),
                ]);
                $batchId = (int)$pdo->lastInsertId();

                $rawInsert = $pdo->prepare(
                    "INSERT INTO raw_source_records
                     (organization_id, club_id, data_source_id, import_batch_id, source_dataset,
                      external_record_id, source_row, record_hash, raw_json, mapping_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );

                $capturedRows = 0;
                foreach ($preparedSheets as $sheetName => $sheetData) {
                    foreach ($sheetData['rows'] as $record) {
                        $rowNumber = (int)$record['row_number'];
                        $values = (array)$record['values'];
                        $payload = [
                            'source' => [
                                'type' => 'xlsx',
                                'file_sha256' => $fileHash,
                                'sheet' => $sheetName,
                                'row' => $rowNumber,
                                'mapping_version' => (string)($mapping['version'] ?? 'unknown'),
                            ],
                            'headers' => $sheetData['headers'],
                            'values' => $values,
                        ];
                        $rawJson = raw_json($payload);
                        $recordHash = hash('sha256', $sheetName . "\n" . $rawJson);
                        $externalRecordId = 'xlsx:' . substr($fileHash, 0, 32) . ':row:' . $rowNumber;

                        $rawInsert->execute([
                            $context['organization_id'],
                            $context['club_id'],
                            $context['data_source_id'],
                            $batchId,
                            $sheetName,
                            $externalRecordId,
                            $rowNumber,
                            $recordHash,
                            $rawJson,
                        ]);
                        $capturedRows++;
                    }
                }

                $batchUpdate = $pdo->prepare(
                    "UPDATE import_batches
                     SET status='completed', imported_rows=?, skipped_rows=0, failed_rows=0,
                         completed_at=NOW(), notes=CONCAT(notes, ' | Normalized writes: OFF')
                     WHERE id=?"
                );
                $batchUpdate->execute([$capturedRows, $batchId]);

                $pdo->commit();

                $result = [
                    'duplicate' => false,
                    'batch_id' => $batchId,
                    'captured_rows' => $capturedRows,
                    'skipped_rows' => 0,
                    'file_hash' => $fileHash,
                    'file_name' => $originalName,
                ];
                $success = 'Raw source capture completed. Normalized business tables were not modified.';
            } catch (Throwable $transactionError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $transactionError;
            }
        }
    }

    $historyStmt = $pdo->prepare(
        "SELECT id, original_file_name, status, total_rows, imported_rows, skipped_rows, failed_rows,
                file_sha256, created_at, completed_at
         FROM import_batches
         WHERE organization_id=? AND import_type='excel_raw_capture'
         ORDER BY id DESC LIMIT 8"
    );
    $historyStmt->execute([$context['organization_id']]);
    $history = $historyStmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Raw Source Capture - Healthcare Wellness Club</title>
  <link rel="stylesheet" href="assets/import.css">
  <style>
    .raw-warning{padding:14px 16px;border:1px solid #edd9a8;border-radius:13px;background:#fff9e9;color:#725314;font-size:.8rem;line-height:1.6}.raw-check{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px;border:1px solid #dce8e1;border-radius:12px;background:#fff}.raw-check input{margin-top:3px}.raw-history{grid-column:span 12}.raw-history table{width:100%;border-collapse:collapse;margin-top:14px;font-size:.76rem}.raw-history th,.raw-history td{padding:10px;border-bottom:1px solid #e9efeb;text-align:left}.raw-history th{color:#607169;font-size:.68rem;text-transform:uppercase}.raw-badge{display:inline-flex;padding:5px 8px;border-radius:8px;background:#eaf8ef;color:#176f45;font-weight:800;font-size:.67rem}.raw-badge.duplicate{background:#edf4ff;color:#2f6fd6}@media(max-width:760px){.raw-history{overflow:auto}.raw-history table{min-width:720px}}
  </style>
</head>
<body>
<header class="imp-topbar">
  <div class="imp-topbar-inner">
    <a class="imp-brand" href="index.php">
      <img src="../img/logo.png" alt="Healthcare Wellness Club logo">
      <span><strong>Healthcare Wellness Club</strong><small>Business OS • Raw Source Capture</small></span>
    </a>
    <nav class="imp-nav" aria-label="Business navigation">
      <a href="import.php">← Mapping Preview</a>
      <a href="index.php">Business OS</a>
    </nav>
  </div>
</header>

<main class="imp-shell">
  <section class="imp-hero">
    <div>
      <div class="imp-kicker">Step 8G • Immutable Source Layer</div>
      <h1>Capture the workbook safely before any business normalization.</h1>
      <p>All eight operational sheets are stored as raw, traceable rows with workbook fingerprint, sheet name, original row number, column letters and headings. This step does not create members, orders, UMS, income or royalty records.</p>
    </div>
    <div class="imp-safety">
      <span class="imp-chip good">Transaction protected</span>
      <span class="imp-chip good">Duplicate workbook blocked</span>
      <span class="imp-chip good">Normalized writes OFF</span>
    </div>
  </section>

  <section class="imp-grid">
    <article class="imp-card imp-upload">
      <h2>Capture reviewed source workbook</h2>
      <p>Use the same <strong>Master_Personal_Tracking.xlsx</strong> that passed Step 8 mapping validation.</p>

      <div class="raw-warning"><strong>Important:</strong> this is the first step that writes workbook rows to MySQL. It writes only to <code>import_batches</code> and <code>raw_source_records</code>. If any validation or insert fails, the transaction is rolled back.</div>

      <form method="post" enctype="multipart/form-data" class="imp-drop">
        <label for="workbook">Excel workbook (.xlsx)</label>
        <input id="workbook" name="workbook" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <label class="raw-check">
          <input type="checkbox" name="confirm_raw_capture" value="yes" required>
          <span><strong>I understand this captures raw source rows only.</strong><br><small>No normalized member/order/UMS/income/royalty mapping will run in this step.</small></span>
        </label>
        <button class="imp-submit" type="submit">Capture Raw Source Safely →</button>
        <div class="imp-hint">The uploaded XLSX stays temporary. The database stores row data and source traceability, not the original workbook file.</div>
      </form>

      <?php if ($error !== null): ?>
        <div class="imp-alert"><strong>Raw capture failed:</strong> <?= raw_h($error) ?></div>
      <?php elseif ($success !== null): ?>
        <div class="imp-alert good"><strong>Success:</strong> <?= raw_h($success) ?></div>
      <?php endif; ?>
    </article>

    <aside class="imp-card imp-plan">
      <h2>Capture boundary</h2>
      <p>Only the reviewed source layer is written in Step 8G.</p>
      <div class="imp-plan-list">
        <div class="imp-plan-row"><div><b>Write</b><span>import_batches</span></div><em>YES</em></div>
        <div class="imp-plan-row"><div><b>Write</b><span>raw_source_records</span></div><em>YES</em></div>
        <div class="imp-plan-row"><div><b>Members / UMS</b><span>Normalized mapping</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Orders / VP</b><span>Normalized mapping</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Income / Royalty</b><span>Normalized mapping</span></div><em>OFF</em></div>
        <div class="imp-plan-row"><div><b>Derived Sheets 1–6</b><span>Formula reports</span></div><em>SKIP</em></div>
      </div>
    </aside>

    <?php if ($result !== null): ?>
      <section class="imp-summary" aria-label="Raw capture result">
        <article class="imp-kpi green"><small>Batch ID</small><strong>#<?= (int)$result['batch_id'] ?></strong><span><?= raw_h((string)$result['file_name']) ?></span></article>
        <article class="imp-kpi blue"><small>Raw Rows</small><strong><?= number_format((int)$result['captured_rows']) ?></strong><span><?= $result['duplicate'] ? 'Previously captured' : 'Captured in this batch' ?></span></article>
        <article class="imp-kpi gold"><small>Duplicate Protection</small><strong><?= $result['duplicate'] ? 'BLOCKED' : 'CLEAN' ?></strong><span>Workbook SHA-256 checked</span></article>
        <article class="imp-kpi"><small>Normalized Writes</small><strong>0</strong><span>Still intentionally disabled</span></article>
      </section>
    <?php endif; ?>

    <article class="imp-card raw-history">
      <h2>Raw capture history</h2>
      <p>Recent immutable workbook capture batches for the current organization.</p>
      <?php if (!$history): ?>
        <div class="imp-hint">No raw workbook capture batch exists yet.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table>
            <thead><tr><th>Batch</th><th>Workbook</th><th>Status</th><th>Total</th><th>Captured</th><th>Failed</th><th>Fingerprint</th><th>Completed</th></tr></thead>
            <tbody>
            <?php foreach ($history as $batch): ?>
              <tr>
                <td>#<?= (int)$batch['id'] ?></td>
                <td><?= raw_h((string)$batch['original_file_name']) ?></td>
                <td><span class="raw-badge"><?= raw_h((string)$batch['status']) ?></span></td>
                <td><?= number_format((int)$batch['total_rows']) ?></td>
                <td><?= number_format((int)$batch['imported_rows']) ?></td>
                <td><?= number_format((int)$batch['failed_rows']) ?></td>
                <td><?= raw_h(substr((string)$batch['file_sha256'], 0, 14)) ?>…</td>
                <td><?= raw_h((string)($batch['completed_at'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </article>

    <div class="imp-footer-note"><strong>Next boundary:</strong> after raw capture is verified, Step 8H will reconcile row counts and source integrity. Only after that will normalized mapping be enabled sheet-by-sheet.</div>
  </section>
</main>
</body>
</html>
