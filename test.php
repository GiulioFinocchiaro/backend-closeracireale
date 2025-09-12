<?php
echo __DIR__;
$dir = __DIR__ . '/cdn/';
$file = $dir . 'test.txt';

if (!is_dir($dir)) {
    die("Cartella NON esiste!");
}

if (!is_writable($dir)) {
    die("Cartella NON scrivibile!");
}

file_put_contents($file, "ok");
echo "Funzionante!";
