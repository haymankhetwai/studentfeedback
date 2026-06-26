<?php
require_once __DIR__ . '/config/db.php';

$sqlFile = file_get_contents(__DIR__ . '/database/migrate_v7.sql');
$statements = array_filter(array_map('trim', explode(';', $sqlFile)), fn($s) => $s !== '' && !str_starts_with(trim($s), '--') && !str_starts_with(trim($s), 'USE'));

$success = 0;
$failed = 0;
$errors = [];

foreach ($statements as $stmt) {
    $clean = trim($stmt);
    if ($clean === '' || str_starts_with($clean, '--') || str_starts_with($clean, 'USE')) continue;
    try {
        $conn->query($stmt);
        $success++;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'doesn\'t exist') || str_contains($msg, 'Unknown column') || str_contains($msg, 'can\'t drop')) {
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
<head><title>SFMS v7 Migration</title></head>
<body style="font-family:system-ui;padding:40px">
    <h1>SFMS v7 — Make Feedback Anonymous</h1>
    <p style="color:green">Statements succeeded: <?= $success ?></p>
    <p style="color:<?= $failed ? 'red' : 'green' ?>">Statements failed: <?= $failed ?></p>
    <?php if ($errors): ?>
        <h3 style="color:red">Errors:</h3>
        <ul><?php foreach ($errors as $e): ?><li style="color:red;font-size:13px"><?= htmlspecialchars($e) ?></li><?php endforeach ?></ul>
    <?php endif; ?>
    <?php if ($failed === 0): ?>
        <p style="color:green;font-weight:bold">Migration complete! feedback_ratings and feedback_comments now have no student_id column.</p>
    <?php endif; ?>
    <h3>Verification:</h3>
    <pre><?php
    $tables = ['feedback_ratings', 'feedback_comments'];
    foreach ($tables as $t) {
        $r = $conn->query("DESCRIBE $t");
        echo "$t columns:\n";
        while ($row = $r->fetch_assoc()) {
            echo "  {$row['Field']} ({$row['Type']})\n";
        }
        echo "\n";
    }
    ?></pre>
    <p style="margin-top:20px"><a href="student/my_sections.php">← Go to Student Portal</a></p>
</body>
</html>
