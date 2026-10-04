<?php
/**
 * delete_deliveries.php
 * --------------------------------------------------------------------
 * Site Delivery Management System - Delete Deliveries Handler
 * --------------------------------------------------------------------
 * - Handles deletion of one or more deliveries by ID (POST).
 * - Redirects back to index.php after processing.
 * - Accepts array of IDs via POST 'delete_ids[]'.
 * --------------------------------------------------------------------
 */

require_once 'db.php';
require_once __DIR__ . '/includes/admin_auth.php';
admin_require($pdo);


if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
    $ids = array_map('intval', $_POST['delete_ids']);
    if (count($ids)) {
        // Prepare statement with as many placeholders as needed
        $qMarks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM deliveries WHERE id IN ($qMarks)");
        $stmt->execute($ids);
    }
}

header('Location: index.php?msg=' . urlencode('Selected deliveries deleted.'));
exit;