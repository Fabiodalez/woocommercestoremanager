<?php
echo "Attempting to connect to database and check 'users' table schema...<br>";
$db_path = __DIR__ . '/database.db';
echo "Expected DB path: " . htmlspecialchars($db_path) . "<br>";

if (!file_exists($db_path)) {
    die("ERROR: Database file does not exist at the expected path: " . htmlspecialchars($db_path));
}
echo "Database file exists. Last modified: " . date("Y-m-d H:i:s", filemtime($db_path)) . "<br>";

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Successfully connected to the database.<br>";

    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users';");
    $schema_sql = $stmt->fetchColumn();

    if ($schema_sql) {
        echo "Schema for 'users' table:<br><pre>" . htmlspecialchars($schema_sql) . "</pre><br>";
        if (strpos(strtolower($schema_sql), 'failed_login_attempts') !== false) {
            echo "<strong style='color:green;'>SUCCESS: 'failed_login_attempts' column IS PRESENT in the schema definition.</strong><br>";
        } else {
            echo "<strong style='color:red;'>ERROR: 'failed_login_attempts' column IS MISSING from the schema definition.</strong><br>";
        }
    } else {
        echo "<strong style='color:red;'>ERROR: 'users' table does not exist in the database.</strong><br>";
    }

    // Prova una query che usa la colonna
    echo "Attempting to query the 'failed_login_attempts' column...<br>";
    $stmt_check = $pdo->query("SELECT failed_login_attempts FROM users LIMIT 1;");
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);
    echo "<strong style='color:green;'>SUCCESS: Query on 'failed_login_attempts' column executed without SQL error (result might be empty if table is empty).</strong><br>";


} catch (PDOException $e) {
    echo "<strong style='color:red;'>PDOException: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    if (strpos($e->getMessage(), 'no such column') !== false) {
        echo "This confirms the 'no such column' error from a direct connection.<br>";
    }
} catch (Exception $e) {
    echo "<strong style='color:red;'>Exception: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
}
?>