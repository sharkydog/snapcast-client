<?php
require_once __DIR__.'/vendor/autoload.php';

use SharkyDog\Snapcast;

// adjust address, port and data dir
$snapc = new Snapcast\Client('192.168.0.123', 1705);
$plapp = new Snapcast\App\RestorePlayerStream(__DIR__.'/data');

$plapp->client($snapc);
$snapc->connect();
