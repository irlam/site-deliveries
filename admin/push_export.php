<?php
// /admin/push_export.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
admin_require($pdo);

/* -----------------------------------------------------------
   Ensure "notes" column exists so export always includes it
----------------------------------------------------------- */
try {
    $pdo->query("SELECT notes FROM push_broadcasts LIMIT 0");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE push_broadcasts ADD COLUMN notes TEXT NULL AFTER body");
    } catch (Throwable $e2) {
        // ignore if cannot alter; export will still work without notes
    }
}

/* -----------------------------------------------------------
   Fetch data
----------------------------------------------------------- */
$format = strtolower((string)($_GET['format'] ?? 'csv')); // csv|pdf
$rows   = [];

$st = $pdo->query("
    SELECT
      id,
      created_at,
      title,
      body,
      url,
      icon,
      badge,
      tag,
      urgency,
      require_interaction,
      sent,
      failed,
      notes
    FROM push_broadcasts
    ORDER BY id DESC
");
if ($st) {
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -----------------------------------------------------------
   CSV (default)
----------------------------------------------------------- */
if ($format !== 'pdf') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="push_broadcasts.csv"');

    $fh = fopen('php://output', 'w');
    fputcsv($fh, [
        'id','created_at','title','body','url','icon','badge','tag',
        'urgency','require_interaction','sent','failed','notes'
    ]);
    foreach ($rows as $r) {
        // flatten booleans/nulls
        $r['require_interaction'] = (int)($r['require_interaction'] ?? 0);
        $r['sent']   = (int)($r['sent']   ?? 0);
        $r['failed'] = (int)($r['failed'] ?? 0);
        fputcsv($fh, $r);
    }
    fclose($fh);
    exit;
}

/* -----------------------------------------------------------
   PDF (requires dompdf/dompdf)
   - Never fatal: shows helpful page if Dompdf not installed
----------------------------------------------------------- */
$dompdfClass   = 'Dompdf\\Dompdf';
$optionsClass  = 'Dompdf\\Options';

// Try Composer autoload if Dompdf not yet loaded
if (!class_exists($dompdfClass)) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

if (!class_exists($dompdfClass)) {
    // Friendly message instead of HTTP 500
    http_response_code(501);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <title>PDF export is not enabled</title>
    <style>
      body{font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:0;background:#0b1220;color:#e5e7eb}
      .wrap{max-width:860px;margin:40px auto;padding:24px}
      code{background:#111827;padding:2px 6px;border-radius:6px}
      a{color:#9ecbff}
      h1{margin:0 0 10px}
      ol{margin:8px 0 0 20px}
      .box{background:#111827;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:16px}
    </style>
    <div class="wrap">
      <h1>PDF export is not enabled</h1>
      <p>Install Composer package <code>dompdf/dompdf</code> (v2.x) for this site.</p>
      <div class="box">
        <ol>
          <li>Plesk → <b>PHP Composer</b> for this domain.</li>
          <li>Add <code>"dompdf/dompdf": "^2.0"</code> to <code>require</code> (or use the Install UI).</li>
          <li>Run Install/Update so <code>vendor/autoload.php</code> exists.</li>
        </ol>
      </div>
      <p class="mt">Then revisit <code>/admin/push_export.php?format=pdf</code>.</p>
    </div>
    <?php
    exit;
}

try {
    /** @var Dompdf\Options $opts */
    $opts = new $optionsClass();
    $opts->set('isRemoteEnabled', true);

    /** @var Dompdf\Dompdf $dompdf */
    $dompdf = new $dompdfClass($opts);

    // Helper
    $h = static function ($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };

    // Build rows HTML
    $rowsHtml = '';
    foreach ($rows as $r) {
        $rowsHtml .= '<tr>'
            .'<td>'.(int)$r['id'].'</td>'
            .'<td>'.$h($r['created_at']).'</td>'
            .'<td><b>'.$h($r['title']).'</b><br><small>'.nl2br($h($r['body'])).'</small></td>'
            .'<td>'.$h($r['tag'] ?: '—').'</td>'
            .'<td>'.$h($r['urgency']).'</td>'
            .'<td>'.(int)$r['sent'].' / '.(int)$r['failed'].'</td>'
            .'<td>'.$h((string)($r['notes'] ?? '')).'</td>'
            .'</tr>';
    }

    // Printable HTML
    $html = <<<HTML
    <!doctype html>
    <meta charset="utf-8">
    <style>
      @page { margin: 24px; }
      body{font:12px/1.45 -apple-system,system-ui,Segoe UI,Roboto,Helvetica,Arial}
      h1{font-size:16px;margin:0 0 8px}
      table{width:100%;border-collapse:collapse}
      th,td{border:1px solid #ddd;padding:6px;vertical-align:top}
      th{background:#f5f5f5}
    </style>
    <h1>Push Broadcasts</h1>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>When</th>
          <th>Title / Body</th>
          <th>Tag</th>
          <th>Urgency</th>
          <th>Result</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        {$rowsHtml}
      </tbody>
    </table>
    HTML;

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output
    $dompdf->stream('push_broadcasts.pdf', ['Attachment' => true]);
    exit;

} catch (Throwable $e) {
    // Graceful failure page (never blank 500)
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <meta charset="utf-8">
    <title>PDF export failed</title>
    <style>
      body{font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:0;background:#fafafa;color:#222}
      .wrap{max-width:860px;margin:40px auto;padding:24px}
      pre{white-space:pre-wrap;background:#fff;border:1px solid #ddd;padding:12px;border-radius:6px}
    </style>
    <div class="wrap">
      <h1>PDF export failed</h1>
      <p>Check your Dompdf installation/configuration.</p>
      <pre><?= htmlspecialchars($e->getMessage(), ENT_QUOTES) ?></pre>
    </div>
    <?php
    exit;
}
