<?php
declare(strict_types=1);

/**
 * Run API contracts against a disposable SQLite fixture. No production DB or
 * credentials are needed, and no file is ever written to the deployed app.
 */
$root = sys_get_temp_dir() . '/suite-delivery-test-' . bin2hex(random_bytes(6));
mkdir($root . '/api', 0700, true);
mkdir($root . '/includes', 0700, true);

try {
    foreach (['suite-summary.php', 'suite-references.php'] as $file) {
        if (!copy(dirname(__DIR__) . '/api/' . $file, $root . '/api/' . $file)) {
            throw new RuntimeException('Cannot stage ' . $file);
        }
    }

    copy(dirname(__DIR__) . '/includes/suite-auth.php', $root . '/includes/suite-auth.php');

    $dbPath = $root . '/fixture.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE deliveries (
        id INTEGER PRIMARY KEY, status TEXT NOT NULL, due_datetime TEXT NOT NULL,
        arrived_at TEXT, completed_at TEXT, no_show INTEGER NOT NULL DEFAULT 0
    )');

    $tz = new DateTimeZone('Europe/London');
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
    $stmt = $pdo->prepare(
        'INSERT INTO deliveries (status, due_datetime, arrived_at, completed_at, no_show)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ([
        ['Booked in',  '09:00:00', null, null, 0],
        ['Booked in',  '09:20:00', 'arrived', null, 0],
        ['Completed',  '09:40:00', 'arrived', 'completed', 0],
        ['Cancelled',  '10:00:00', null, null, 0],
        ['Booked in',  '10:20:00', null, null, 1],
        ['Booked in',  '10:40:00', null, null, 0],
    ] as [$status, $time, $arrived, $completed, $noShow]) {
        $date = $today->format('Y-m-d') . ' ' . $time;
        $stmt->execute([
            $status, $date,
            $arrived ? $date : null,
            $completed ? $date : null,
            $noShow,
        ]);
    }
    unset($pdo, $stmt);

    file_put_contents($root . '/db.php', '<?php $pdo = new PDO('
        . var_export('sqlite:' . $dbPath, true)
        . '); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);');

    $request = static function (string $file, ?string $key, string $supplied, array $query=[], ?int $company=null) use ($root): array {
        $code = 'putenv(' . var_export(
            'CONSTRUCTION_SUITE_API_KEY=' . ($key ?? ''), true
        ) . '); $_SERVER["HTTP_X_CONSTRUCTION_SUITE_KEY"] = '
        . var_export($supplied, true)
        . '; $_GET='.var_export($query,true).'; '.($company===null?'':'define("CONSTRUCTION_SUITE_COMPANY_ID",'.$company.');').' require ' . var_export($root . '/api/' . $file, true) . ';';

        $proc = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
        ], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Could not start PHP subprocess');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $json = json_decode($output, true);
        if ($exit !== 0 || !is_array($json)) {
            throw new RuntimeException("Invalid API response: " . $errors . " / " . $output);
        }
        return $json;
    };
    $check = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    };

    $key = str_repeat('K', 48);
    $summaryFile = 'suite-summary.php';
    $check(
        ($request($summaryFile, null, '')['error'] ?? '') === 'suite_integration_not_configured',
        'Missing secret must fail closed'
    );
    $check(
        ($request($summaryFile, $key, 'wrong')['error'] ?? '') === 'unauthorized',
        'Incorrect secret must be rejected'
    );
    $data = $request($summaryFile, $key, $key);
    $metrics = $data['metrics'] ?? [];
    foreach ([
        'total' => 6, 'today' => 5, 'awaiting_arrival' => 2,
        'completed' => 1, 'no_shows' => 1
    ] as $metric => $expected) {
        $check(($metrics[$metric] ?? null) === $expected, 'Incorrect count for ' . $metric);
    }
    $check(is_int($metrics['overdue'] ?? null), 'Overdue count must be numeric');

    $refs = $request('suite-references.php', $key, $key);
    $check(($refs['ok'] ?? false) === true, 'Reference lookup did not authenticate');
    $check(($refs['items'] ?? null) === [], 'Single-site calendar must have no fictional sites');

    $pdo=new PDO('sqlite:'.$dbPath);$pdo->exec('ALTER TABLE deliveries ADD COLUMN site_id INTEGER; ALTER TABLE deliveries ADD COLUMN company_id INTEGER; CREATE TABLE logistics_sites(id INTEGER PRIMARY KEY,name TEXT,active INTEGER); INSERT INTO logistics_sites VALUES(1,"Rochdale Road",1),(2,"Other site",1); UPDATE deliveries SET site_id=1,company_id=1; UPDATE deliveries SET site_id=2,company_id=2 WHERE id=6');
    $check(($request($summaryFile,$key,$key)['error']??'')==='site_reference_required','Migrated summary refuses unscoped requests');
    $check(($request($summaryFile,$key,$key,['site'=>1])['metrics']['total']??null)===5,'Site summary excludes another site');
    $check(($request($summaryFile,$key,$key,['site'=>1],2)['metrics']['total']??null)===0,'Server-bound company reporting excludes another company');
    $check(($request($summaryFile,$key,$key,['site'=>99])['error']??'')==='site_not_found','Unknown Suite site mapping denied');
    $found=$request('suite-references.php',$key,$key)['items'];$check(count($found)===2&&$found[1]===['value'=>'1','label'=>'Rochdale Road'],'References match the Suite discovery value/label contract');unset($pdo);
    echo "PASS: Deliveries Suite API security, summary metrics and mapping contracts.\n";
} finally {
    foreach (['api/suite-summary.php', 'api/suite-references.php', 'db.php', 'fixture.sqlite'] as $file) {
        @unlink($root . '/' . $file);
    }
    @unlink($root . '/includes/suite-auth.php');
    @rmdir($root . '/includes');
    @rmdir($root . '/api');
    @rmdir($root);
}
