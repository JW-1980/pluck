<?php

// Router for `php -S`: behaviours the fetcher must survive on a real socket.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$port = $_SERVER['SERVER_PORT'];

switch ($path) {
    case '/small':
        header('Content-Type: text/html');
        echo '<p>hello</p>';
        break;
    case '/big': // 5 MB with a declared Content-Length
        header('Content-Length: '.(5 * 1024 * 1024));
        echo str_repeat('a', 5 * 1024 * 1024);
        break;
    case '/chunked': // 5 MB, no Content-Length: nothing to pre-check
        header('Content-Type: text/html');
        for ($i = 0; $i < 80; $i++) {
            echo str_repeat('b', 64 * 1024);
            flush();
        }
        break;
    case '/slow': // 5 MB over ~4 s: only an early abort can finish quickly
        header('Content-Type: text/html');
        for ($i = 0; $i < 80; $i++) {
            echo str_repeat('d', 64 * 1024);
            flush();
            usleep(50_000);
        }
        break;
    case '/bomb': // ~20 KB on the wire, 40 MB once decoded
        header('Content-Encoding: gzip');
        header('Content-Type: text/html');
        echo gzencode(str_repeat('c', 40 * 1024 * 1024), 9);
        break;
    case '/gzip-small':
        header('Content-Encoding: gzip');
        echo gzencode('<p>hello gzip</p>');
        break;
    case '/redirect':
        header('Location: /small', true, 302);
        break;
    case '/redirect-out':
        header("Location: http://other.test:{$port}/small", true, 302);
        break;
    case '/403':
        http_response_code(403);
        echo '<HTML><TITLE>Access Denied</TITLE></HTML>';
        break;
    case '/host':
        echo $_SERVER['HTTP_HOST'];
        break;
    default:
        http_response_code(404);
}
