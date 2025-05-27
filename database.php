<?php
// database.php - Database setup and management (con la nuova funzione count)

// Assicurati che la costante di debug sia definita, se vuoi usarla qui.
// Se login.php o un file di config globale la definisce, questo è opzionale qui.
if (!defined('LOGIN_DEBUG_MODE')) {
    define('LOGIN_DEBUG_MODE', false); // Default a false se non definito altrove
}

// Funzione helper per il logging all'interno di questa classe
function db_debug_log($message) {
    if (LOGIN_DEBUG_MODE && function_exists('error_log')) {
        error_log("DATABASE.PHP DEBUG: " . $message);
    }
}

class Database {
    private static $instance = null;
    private $pdo;
    private $db_path;
    
    private function __construct() {
        $this->db_path = __DIR__ . '/database.db'; 
        db_debug_log("Constructor: db_path set to " . $this->db_path);
        $this->initializeDatabase();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            db_debug_log("getInstance: Creating new Database instance.");
            self::$instance = new self();
        } else {
            db_debug_log("getInstance: Returning existing Database instance.");
        }
        return self::$instance;
    }
    
    private function initializeDatabase() {
        db_debug_log("initializeDatabase: Starting initialization.");
        try {
            if (!file_exists($this->db_path) && php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF'] ?? '') !== 'install.php') {
                 db_debug_log("initializeDatabase: Database file '{$this->db_path}' does not exist and not in install/CLI context.");
                 // Potresti voler lanciare un'eccezione qui per forzare l'installazione
                 // throw new Exception('Database file not found. Please run the installation script.');
            }

            $this->pdo = new PDO('sqlite:' . $this->db_path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            db_debug_log("initializeDatabase: PDO connection successful. Foreign keys ON.");
            
        } catch (PDOException $e) {
            db_debug_log("initializeDatabase: PDOException caught: " . $e->getMessage());
            if (strpos($e->getMessage(), 'unable to open database file') !== false && php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF'] ?? '') !== 'install.php') {
                 throw new Exception('Database not found at ' . htmlspecialchars($this->db_path) . '. Please ensure the application is installed correctly. Original error: ' . $e->getMessage());
            }
            throw new Exception('Database connection or setup failed: ' . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function beginTransaction() {
        db_debug_log("Beginning transaction.");
        return $this->pdo->beginTransaction();
    }
    
    public function commit() {
        db_debug_log("Committing transaction.");
        return $this->pdo->commit();
    }
    
    public function rollback() {
        db_debug_log("Rolling back transaction.");
        return $this->pdo->rollback();
    }
    
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    public function prepare($sql) {
        // db_debug_log("Preparing SQL: " . $sql); // Potrebbe essere molto verboso
        return $this->pdo->prepare($sql);
    }
    
    public function execute($sql, $params = []) {
        try {
            // db_debug_log("Executing SQL: " . $sql . " | Params: " . json_encode($params));
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            db_debug_log("SQL EXECUTE ERROR: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            throw $e;
        }
    }
    
    public function fetch($sql, $params = []) {
        try {
            // db_debug_log("Fetching SQL: " . $sql . " | Params: " . json_encode($params));
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            db_debug_log("SQL FETCH ERROR: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            throw $e;
        }
    }
    
    public function fetchAll($sql, $params = []) {
        try {
            // db_debug_log("Fetching All SQL: " . $sql . " | Params: " . json_encode($params));
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            db_debug_log("SQL FETCHALL ERROR: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            throw $e;
        }
    }

    public function fetchColumn($sql, $params = [], $column_number = 0) {
        try {
            // db_debug_log("Fetching Column SQL: " . $sql . " | Params: " . json_encode($params));
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn($column_number);
        } catch (PDOException $e) {
            db_debug_log("SQL FETCHCOLUMN ERROR: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            throw $e;
        }
    }
    
    private function castValue($value, $type) {
        switch (strtolower((string) $type)) { // Aggiunto cast a string per sicurezza
            case 'boolean': case 'bool': return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer': case 'int': return (int) $value;
            case 'float': case 'double': return (float) $value;
            case 'json': return json_decode($value, true);
            case 'string':
            default: return (string) $value;
        }
    }

    public function getSystemSetting($key, $default = null) {
        try {
            $setting = $this->fetch('SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?', [$key]);
            if ($setting && isset($setting['setting_value']) && isset($setting['setting_type'])) {
                return $this->castValue($setting['setting_value'], $setting['setting_type']);
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'no such table: system_settings') !== false) {
                db_debug_log("getSystemSetting: Table 'system_settings' not found, returning default for '{$key}'.");
                return $default;
            }
            db_debug_log("Error getting system setting '{$key}': " . $e->getMessage());
        }
        return $default;
    }

    public function setSystemSetting($key, $value, $type = 'string', $description = null) {
        if (strtolower($type) === 'json') { // Confronto case-insensitive
            $value = json_encode($value);
        } elseif (strtolower($type) === 'boolean' || strtolower($type) === 'bool') {
            $value = $value ? '1' : '0';
        }
        
        $sql = 'INSERT OR REPLACE INTO system_settings (setting_key, setting_value, setting_type, description, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)';
        return $this->execute($sql, [$key, $value, (string) $type, $description]); // Cast type a string
    }

    // NUOVA FUNZIONE COUNT FORNITA DA TE
   public function count(string $table, array $conditions = []): int
{
    // 1) Validazione nome tabella
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        db_debug_log("COUNT: Table name non valido: {$table}");
        throw new InvalidArgumentException(
            "Invalid table name for count: " . htmlspecialchars($table)
        );
    }

    $sql     = "SELECT COUNT(*) FROM `{$table}`";
    $params  = [];
    $clauses = [];

    // 2) Elaborazione condizioni
    if (!empty($conditions)) {
        foreach ($conditions as $key => $val) {
            $column   = null;
            $operator = null;
            $value    = null;

            // A) Formato tuple: [col, op, value]
            if (is_int($key) && is_array($val) && count($val) === 3) {
                list($column, $operator, $value) = $val;
            }
            // B) Chiave con operatore esplicito: deve contenere almeno uno spazio
            elseif (is_string($key)
                && strpos($key, ' ') !== false
                && preg_match(
                    '/^([\w.]+)\s*'
                    . '(>=|<=|<>|!=|=|<|>|LIKE|NOT\s+LIKE|IN|NOT\s+IN|IS|IS\s+NOT)$/i',
                    $key,
                    $m
                )
            ) {
                $column   = $m[1];
                $operator = strtoupper($m[2]);
                $value    = $val;
            }
            // C) Nessun operatore valido: fallback "="
            else {
                $column   = (string)$key;
                $operator = '=';
                $value    = $val;
            }

            // 3) Validazione colonna
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $column)) {
                db_debug_log("COUNT: Column name non valido: {$column}");
                throw new InvalidArgumentException(
                    "Invalid column name in count condition: " . htmlspecialchars($column)
                );
            }

            // 4) Whitelist operatori
            $allowed = [
                '=', '>', '<', '>=', '<=',
                '!=', '<>', 'LIKE', 'NOT LIKE',
                'IN', 'NOT IN', 'IS', 'IS NOT'
            ];
            if (!in_array($operator, $allowed, true)) {
                db_debug_log("COUNT: Operatore non ammesso: {$operator}");
                throw new InvalidArgumentException(
                    "Invalid operator in count condition: " . htmlspecialchars($operator)
                );
            }

            // 5) Costruzione clausola SQL
            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                if (!is_array($value) || empty($value)) {
                    throw new InvalidArgumentException(
                        "Value for IN/NOT IN must be a non-empty array."
                    );
                }
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $clauses[]    = "`{$column}` {$operator} ({$placeholders})";
                $params       = array_merge($params, $value);
            }
            elseif (in_array($operator, ['IS', 'IS NOT'], true)) {
                if (!is_null($value) && strtolower($value) !== 'null') {
                    throw new InvalidArgumentException(
                        "Value for IS/IS NOT must be NULL."
                    );
                }
                $clauses[] = "`{$column}` {$operator} NULL";
            }
            else {
                $clauses[] = "`{$column}` {$operator} ?";
                $params[]  = $value;
            }
        }

        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }

    // 6) Debug & fetch
    db_debug_log("COUNT SQL: {$sql} | PARAMS: " . json_encode($params));
    return (int) $this->fetchColumn($sql, $params);
}

    public function getDatabaseInfo() {
        $file_size = file_exists($this->db_path) ? filesize($this->db_path) : 0;
        $schema_version_result = $this->fetchColumn("PRAGMA user_version;");
        $schema_version = $schema_version_result !== false ? (int)$schema_version_result : 0; // Assicura int

        $table_count_result = $this->fetch("SELECT COUNT(*) as count FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';");
        $table_count = $table_count_result && isset($table_count_result['count']) ? (int)$table_count_result['count'] : 0;

        $sqlite_version_result = $this->fetchColumn("SELECT sqlite_version();");
        $sqlite_version = $sqlite_version_result ?: 'N/A';

        return [
            'file_path' => $this->db_path,
            'file_size' => $file_size,
            'schema_version' => $schema_version,
            'table_count' => $table_count,
            'sqlite_version' => $sqlite_version
        ];
    }
    
    public function cleanupExpiredSessions() {
        try {
            // Usare datetime('now') è generalmente più sicuro per SQLite (interpreta come UTC)
            $this->execute('DELETE FROM user_sessions WHERE expires_at < datetime("now")');
            db_debug_log("Expired sessions cleaned up.");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'no such table') === false) { // Logga solo se non è "no such table"
                db_debug_log('Error cleaning up expired sessions: ' . $e->getMessage());
            }
        }
    }
    
    public function cleanupOldActivity($days = 30) {
        try {
            $this->execute('DELETE FROM user_activity WHERE created_at < datetime("now", "-' . intval($days) . ' days")');
            db_debug_log("Old activity (older than {$days} days) cleaned up.");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'no such table') === false) {
                db_debug_log('Error cleaning up old activity: ' . $e->getMessage());
            }
        }
    }

    public function runMaintenance() {
        db_debug_log("Running maintenance tasks...");
        $cleaned = ['sessions' => 0, 'activity' => 0];
        try {
            $stmt_sessions = $this->pdo->prepare('DELETE FROM user_sessions WHERE expires_at < datetime("now")');
            $stmt_sessions->execute();
            $cleaned['sessions'] = $stmt_sessions->rowCount();
            
            $cleanup_days_activity = (int) $this->getSystemSetting('cleanup_activity_days', 90);
            $stmt_activity = $this->pdo->prepare('DELETE FROM user_activity WHERE created_at < datetime("now", "-' . $cleanup_days_activity . ' days")');
            $stmt_activity->execute();
            $cleaned['activity'] = $stmt_activity->rowCount();
            
            $this->pdo->exec('VACUUM;');
            db_debug_log("Maintenance complete. Cleaned sessions: {$cleaned['sessions']}, activity: {$cleaned['activity']}. VACUUM executed.");
        } catch (Exception $e) { // Cattura Exception generica per getSystemSetting
             db_debug_log("Error during maintenance: " . $e->getMessage());
        }
        return $cleaned;
    }
}

// Non è consigliabile inizializzare automaticamente qui.
// Lascia che il codice che lo usa (es. login.php, dashboard.php) chiami Database::getInstance().
?>