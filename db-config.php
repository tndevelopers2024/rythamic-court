<?php
/**
 * Rhythmic Beat Dance Court - MySQL Database Configuration & PDO Helper
 */

// Global config definition
function getDbConfig(): array {
    // Detect if running locally on XAMPP or on live Hostinger (rbdancecourt.com)
    $serverName = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $isLive = (stripos($serverName, 'rbdancecourt.com') !== false);

    if ($isLive) {
        return [
            'host'    => getenv('DB_HOST') ?: 'localhost',
            'port'    => (int)(getenv('DB_PORT') ?: 3306),
            'dbname'  => getenv('DB_NAME') ?: 'u448399733_rhythmic_beat',
            'user'    => getenv('DB_USER') ?: 'u448399733_rb_admin',
            'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'RBdbpass@123',
            'charset' => 'utf8mb4'
        ];
    }

    // Default Localhost XAMPP
    return [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => (int)(getenv('DB_PORT') ?: 3307),
        'dbname'  => getenv('DB_NAME') ?: 'rhythmic_beat',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4'
    ];
}

/**
 * Returns a shared PDO MySQL instance or null on failure
 */
if (!function_exists('getDbConnection')) {
    function getDbConnection(): ?PDO {
        static $pdo = null;
        if ($pdo !== null) return $pdo;

        $config = getDbConfig();
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";

        try {
            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3
            ]);
            return $pdo;
        } catch (PDOException $e) {
            // If primary failed, try the alternate configuration (Hostinger <-> Localhost)
            $altConfig = ($config['user'] === 'root')
                ? [
                    'host' => 'localhost', 'port' => 3306,
                    'dbname' => 'u448399733_rhythmic_beat',
                    'user' => 'u448399733_rb_admin',
                    'pass' => 'RBdbpass@123',
                    'charset' => 'utf8mb4'
                ]
                : [
                    'host' => 'localhost', 'port' => 3307,
                    'dbname' => 'rhythmic_beat',
                    'user' => 'root',
                    'pass' => '',
                    'charset' => 'utf8mb4'
                ];

            try {
                $dsnAlt = "mysql:host={$altConfig['host']};port={$altConfig['port']};dbname={$altConfig['dbname']};charset={$altConfig['charset']}";
                $pdo = new PDO($dsnAlt, $altConfig['user'], $altConfig['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 2
                ]);
                return $pdo;
            } catch (PDOException $eAlt) {
                error_log('[DB Error] Could not connect to MySQL: ' . $eAlt->getMessage());
            }
            return null;
        }
    }
}

return getDbConfig();

