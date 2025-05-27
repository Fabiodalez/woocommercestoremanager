<?php
// check_schema.php - Verifica lo schema del database
require_once 'database.php';

try {
    $db = Database::getInstance();
    
    // Ottieni l'istanza PDO dalla classe Database
    $pdo = $db->getConnection(); // oppure $db->getPDO() se il metodo si chiama così
    
    // Ottieni la struttura della tabella users
    $result = $pdo->query("PRAGMA table_info(users)");
    
    echo "<h2>Schema della tabella 'users':</h2>\n";
    echo "<table border='1' style='border-collapse: collapse;'>\n";
    echo "<tr><th>Nome Colonna</th><th>Tipo</th><th>NOT NULL</th><th>Default</th><th>Primary Key</th></tr>\n";
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['type']) . "</td>";
        echo "<td>" . ($row['notnull'] ? 'YES' : 'NO') . "</td>";
        echo "<td>" . htmlspecialchars($row['dflt_value'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['pk'] ? 'YES' : 'NO') . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    // Verifica specificamente le colonne admin
    echo "<h3>Colonne contenenti 'admin' nel nome:</h3>\n";
    $found_admin_columns = [];
    
    $result = $pdo->query("PRAGMA table_info(users)");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        if (stripos($row['name'], 'admin') !== false || stripos($row['name'], 'adm') !== false) {
            $found_admin_columns[] = $row['name'];
            echo "- " . htmlspecialchars($row['name']) . "\n<br>";
        }
    }
    
    if (empty($found_admin_columns)) {
        echo "Nessuna colonna 'admin' trovata!<br>\n";
    }
    
} catch (Exception $e) {
    echo "Errore: " . $e->getMessage() . "<br>";
    echo "Provo un approccio alternativo...<br><br>";
    
    // Approccio alternativo - accesso diretto al database
    try {
        $db_path = __DIR__ . '/database.db';
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "<h3>Accesso diretto al database:</h3>";
        $result = $pdo->query("PRAGMA table_info(users)");
        
        echo "<table border='1' style='border-collapse: collapse;'>\n";
        echo "<tr><th>Nome Colonna</th><th>Tipo</th><th>NOT NULL</th><th>Default</th><th>Primary Key</th></tr>\n";
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['type']) . "</td>";
            echo "<td>" . ($row['notnull'] ? 'YES' : 'NO') . "</td>";
            echo "<td>" . htmlspecialchars($row['dflt_value'] ?? 'NULL') . "</td>";
            echo "<td>" . ($row['pk'] ? 'YES' : 'NO') . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        
        // Verifica specificamente le colonne admin
        echo "<h3>Colonne contenenti 'admin' nel nome:</h3>\n";
        $found_admin_columns = [];
        
        $result = $pdo->query("PRAGMA table_info(users)");
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if (stripos($row['name'], 'admin') !== false || stripos($row['name'], 'adm') !== false) {
                $found_admin_columns[] = $row['name'];
                echo "- " . htmlspecialchars($row['name']) . "\n<br>";
            }
        }
        
        if (empty($found_admin_columns)) {
            echo "Nessuna colonna 'admin' trovata!<br>\n";
        }
        
    } catch (Exception $e2) {
        echo "Errore anche con accesso diretto: " . $e2->getMessage();
    }
}
?>