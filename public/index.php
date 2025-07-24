<?php
require_once __DIR__ . '/../vendor/autoload.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/middleware/cors.php';
require_once __DIR__ . '/../src/core/Router.php';

use Core\Router;

$router = new Router();
require __DIR__ . '/../src/routes/api.php'; // <<< non serve global se lo passiamo
$router->dispatch();
