<?php
namespace Database;

class Connection {
    private static \mysqli $conn;

    public static function get(): \mysqli {
        if (!isset(self::$conn)) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$conn = new \mysqli(
                $db['host'],
                $db['username'],
                $db['password'],
                $db['database'],
                $db['port']
            );
            self::$conn->set_charset($db['charset']);
        }
        return self::$conn;
    }
}
