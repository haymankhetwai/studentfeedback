<?php
require_once __DIR__ . '/config/db.php';

$sqlFile = file_get_contents(__DIR__ . '/database/migrate_v5.sql');
$statements = array_filter(array_map('trim', explode(';', $sqlFile)), fn($s) => $s !== '' && !str_starts_with(trim($s), '--'));

$success = 0;
$failed = 0;
$errors = [];

foreach ($statements as $stmt) {
    $clean = trim($stmt);
    if ($clean === '' || str_starts_with($clean, '--')) continue;
    try {
        $conn->query($stmt);
        $success++;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate') || str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column')) {
            $success++;
        } else {
            $failed++;
            $errors[] = $msg . ' | ' . substr($clean, 0, 100);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>SFMS v5 Migration</title></head>
<body style="font-family:system-ui;padding:40px">
    <h1>SFMS v5 — Schema Migration (25 → 14 tables)</h1>
    <p style="color:green">Statements succeeded: <?= $success ?></p>
    <p style="color:<?= $failed ? 'red' : 'green' ?>">Statements failed: <?= $failed ?></p>
    <?php if ($errors): ?>
        <h3 style="color:red">Errors:</h3>
        <ul><?php foreach ($errors as $e): ?><li style="color:red;font-size:13px"><?= htmlspecialchars($e) ?></li><?php endforeach ?></ul>
    <?php endif; ?>
    <?php if ($failed === 0): ?>
        <p style="color:green;font-weight:bold">Migration complete!</p>
    <?php endif; ?>
    <h3>Remaining tables:</h3>
    <ul>
    <?php
    $r = $conn->query('SHOW TABLES');
    while ($row = $r->fetch_row()) echo '<li>' . htmlspecialchars($row[0]) . '</li>';
    ?>
    </ul>
    <h3>Data verification:</h3>
    <pre><?php
    $tables = ['feedback_forms','feedback_questions','feedback_submissions','feedback_ratings','feedback_comments','global_feedback_questions'];
    foreach ($tables as $t) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM $t");
        echo "$t: " . $r->fetch_assoc()['c'] . " rows\n";
    }
    $r = $conn->query('SELECT module, COUNT(*) AS c FROM feedback_forms GROUP BY module');
    echo "\nfeedback_forms by module:\n";
    while ($row = $r->fetch_assoc()) echo "  {$row['module']}: {$row['c']}\n";
    $r = $conn->query('SELECT module, COUNT(*) AS c FROM global_feedback_questions GROUP BY module');
    echo "\nglobal_feedback_questions by module:\n";
    while ($row = $r->fetch_assoc()) echo "  {$row['module']}: {$row['c']}\n";
    ?></pre>
</body>
</html>
