<?php
require __DIR__ . '/vendor/autoload.php';

use App\Websocket\Server;

$config = include 'config/app.php';

$server = new Server($config);
$server->start();
