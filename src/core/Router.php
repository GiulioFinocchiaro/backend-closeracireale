<?php
namespace Core;

require_once __DIR__ . "/../../vendor/autoload.php";

use Core\Controller;

class Router
{
    private $routes = [];

    public function add($method, $route, $controller, $action)
    {
        $route = rtrim($route, '/');
        if ($route === '') $route = '/';
        $this->routes[$method][$route] = [$controller, $action];
    }

    public function dispatch()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $uri = preg_replace('#^/index\.php#', '', $uri);

        $basePath = '/backend/public';
        if (str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = rtrim($uri, '/');
        if ($uri === '') $uri = '/';

        $method = $_SERVER['REQUEST_METHOD'];
        header('Content-Type: application/json');


        if (isset($this->routes[$method][$uri])) {
            [$ctrl, $act] = $this->routes[$method][$uri];
            (new $ctrl())->$act();
        } else {
            http_response_code(404);
            echo json_encode(['detail' => 'Not Found']);
        }
    }
}