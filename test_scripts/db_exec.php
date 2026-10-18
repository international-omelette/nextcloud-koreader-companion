<?php

declare(strict_types=1);

/**
 * Tiny SQL helper for the test scripts.
 *
 * It reads the deployed Nextcloud config.php directly, so no credentials are
 * hard-coded and the tests work on both MariaDB/MySQL and SQLite. It never
 * touches application code and is only used by test_scripts/.
 *
 * Usage:
 *   php db_exec.php "SELECT ... FROM {prefix}koreader_metadata"
 *     -> prints one line per row, columns joined by tabs, NULL as `NULL`
 *   php db_exec.php --config datadirectory
 *     -> prints a single config value
 */

$root = getenv('KOREADER_TESTENV') ?: (getenv('HOME') . '/.koreader-testenv');
$configPath = getenv('NC_CONFIG') ?: ($root . '/nextcloud/config/config.php');

if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found at {$configPath}\n");
    exit(2);
}

$CONFIG = [];
require $configPath;
$prefix = $CONFIG['dbtableprefix'] ?? 'oc_';

$arg = $argv[1] ?? '';
if ($arg === '--config') {
    echo $CONFIG[$argv[2] ?? ''] ?? '';
    exit(0);
}
if ($arg === '') {
    fwrite(STDERR, "usage: db_exec.php <sql> | --config <key>\n");
    exit(2);
}

$sql = str_replace('{prefix}', $prefix, $arg);
$dbtype = $CONFIG['dbtype'] ?? 'sqlite3';

try {
    if (str_starts_with($dbtype, 'sqlite')) {
        // Nextcloud stores SQLite as <datadirectory>/<dbname or "owncloud">.db;
        // the test env config omits dbname and uses the default name.
        $dbname = ($CONFIG['datadirectory'] ?? '') . '/' . ($CONFIG['dbname'] ?? 'owncloud') . '.db';
        $pdo = new PDO('sqlite:' . $dbname, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $dbhost = $CONFIG['dbhost'] ?? 'localhost';
        $host = $dbhost;
        $port = null;
        $socket = null;
        if (str_contains($dbhost, ':/')) {
            [$host, $socket] = explode(':', $dbhost, 2);
        } elseif (str_contains($dbhost, ':')) {
            $parts = explode(':', $dbhost);
            $last = array_pop($parts);
            if (ctype_digit($last)) {
                $port = (int)$last;
                $host = implode(':', $parts);
            }
        }
        $dsn = 'mysql:dbname=' . ($CONFIG['dbname'] ?? '');
        if ($socket !== null) {
            $dsn .= ';unix_socket=' . $socket;
        } else {
            $dsn .= ';host=' . $host;
            if ($port !== null) {
                $dsn .= ';port=' . $port;
            }
        }
        $dsn .= ';charset=utf8mb4';
        $pdo = new PDO($dsn, $CONFIG['dbuser'] ?? '', $CONFIG['dbpassword'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    if (preg_match('/^\s*(select|show|pragma|with)\b/i', $sql)) {
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo implode("\t", array_map(
                static fn($v) => $v === null ? 'NULL' : (string)$v,
                $row
            )), "\n";
        }
    } else {
        $pdo->exec($sql);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'db_exec failed: ' . $e->getMessage() . "\n");
    exit(1);
}