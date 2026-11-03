<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'check';
if (! in_array($mode, ['initialize', 'wait', 'check'], true)) {
    fwrite(STDERR, "Usage: php docker/mysql.php [initialize|wait|check]\n");
    exit(1);
}

$initialize = $mode === 'initialize';
$dsn = $initialize
    ? 'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4'
    : 'mysql:host=127.0.0.1;port=3306;dbname=ftp;charset=utf8mb4';
$deadline = microtime(true) + ($mode === 'check' ? 0 : 120);
$connection = null;

do {
    try {
        $connection = new PDO(
            $dsn,
            $initialize ? 'root' : getenv('DB_USERNAME'),
            $initialize ? '' : getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2],
        );
        $connection->query('SELECT 1');
        break;
    } catch (PDOException) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "MySQL connection failed. Check the FTP environment and the existing volume credentials.\n");
            exit(1);
        }
        usleep(500_000);
    }
} while (true);

if ($initialize) {
    try {
        $username = $connection->quote(getenv('DB_USERNAME'));
        $password = $connection->quote(getenv('DB_PASSWORD'));
        $rootPassword = $connection->quote(getenv('MYSQL_ROOT_PASSWORD'));

        $connection->exec('CREATE DATABASE `ftp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach (['localhost', '127.0.0.1'] as $host) {
            $account = $username.'@'.$connection->quote($host);
            $connection->exec("CREATE USER {$account} IDENTIFIED BY {$password}");
            $connection->exec("GRANT ALL PRIVILEGES ON `ftp`.* TO {$account}");
        }
        $connection->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY {$rootPassword}");
    } catch (PDOException) {
        fwrite(STDERR, "MySQL initialization failed; existing data has been retained. Check the local credentials.\n");
        exit(1);
    }
    fwrite(STDOUT, "MySQL database ftp and application account initialized.\n");
}
