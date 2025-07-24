<?php
namespace Core;

use Helpers\Response;

abstract class Controller
{
    protected function json($data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    protected function error(string $message, int $status = 500, int $code=0): void
    {
        Response::json(['success' => false, 'detail' => $message, "code" => $code], $status);
    }
}
