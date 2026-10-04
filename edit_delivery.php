<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/admin_auth.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    admin_require($pdo); // 403 for non-admins
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Admins only.';
    exit;
}

function bad(string $m,int $code=400){ http_response_code($code); echo $m; exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) bad('Invalid id');

$supplier         = trim((string)($_POST['supplier'] ?? ''));
$user_name        = trim((string)($_POST['user_name'] ?? ''));
$material         = trim((string)($_POST['material'] ?? ''));
$quantity         = trim((string)($_POST['quantity'] ?? ''));
$unloading_method = trim((string)($_POST['unloading_method'] ?? ''));
$due              = trim((string)($_POST['due_datetime'] ?? '')); // "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM"

if ($supplier===''||$user_name===''||$material===''||$quantity===''||$unloading_method==='') bad('All fields required');

if ($due !== '') {
    $due = str_replace('T',' ',$due);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$due)) bad('Invalid date/time');
    $due .= ':00';
}

// Build dynamic SQL to allow editing without forcing due_datetime change
$fields = [
    'supplier'         => $supplier,
    'user_name'        => $user_name,
    'material'         => $material,
    'quantity'         => $quantity,
    'unloading_method' => $unloading_method,
];
if ($due !== '') $fields['due_datetime'] = $due;

$sets = [];
$params = [];
foreach ($fields as $k=>$v) {
    $sets[] = "$k = :$k";
    $params[":$k"] = $v;
}
$params[':id'] = $id;

$sql = "UPDATE deliveries SET ".implode(', ',$sets)." WHERE id = :id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo 'success';
} catch (PDOException $e) {
    // Unique slot clash (if due_datetime changed to an already-booked slot)
    if ($e->getCode()==='23000') bad('Slot already booked!',409);
    bad('Edit failed',500);
}
