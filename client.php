<?php
require __DIR__ . '/vendor/autoload.php';

use App\Game\Player;
use App\Websocket\Client;

$config = include 'config/app.php';
function config($key = null)
{
    return $GLOBALS['config'][$key] ?? $GLOBALS['config'];
}

$client = new Client($config['websocket']);
$client->start();
