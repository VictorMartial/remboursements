<?php
// Router for PHP built-in server
// Rewrite all requests to public/index.php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// If it's a real file or directory, serve it
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false; // Let PHP serve the file
}

// Set the script filename and include index.php
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';
chdir(__DIR__.'/public');
require_once __DIR__.'/public/index.php';
