# snapcast-client
Snapcast JSON-RPC api wrapper in PHP

This library provides basic connectivity to the [Snapcast](https://github.com/badaix/snapcast) server using its [JSON-RPC api](https://github.com/badaix/snapcast/blob/develop/doc/json_rpc_api/control.md) and a raw TCP socket.
Commands and responses are linked using [ReactPHP promises](https://github.com/reactphp/promise/tree/3.x), notifications are available as [EventEmitter](https://github.com/igorw/evenement/tree/v3.0.2) events.

The client class has an automatic reconnect feature which can be adjusted or stopped during runtime.

## TL;DR

```php
use SharkyDog\Snapcast;
$snapc = new Snapcast\Client('192.168.0.123', 1705);

$snapc->on('open', function() use($snapc) {
  // second parameter is a \stdClass with request params
  $snapc->call('Client.SetVolume', (object)[
    'id' => '00:21:6a:7d:74:fc',
    'volume' => (object)[
      'muted' => false,
      'percent' => 74
    ]
  ])->then(function(\stdClass $result) {
    // $result is everything in the result object of the json-rpc response message
  })->catch(function(\Exception $e) {
    // error
    // $e->getCode(), $e->getMessage()
  });
});

// listen for Client.OnVolumeChanged notification
$snapc->on('Client.OnVolumeChanged', function(\stdClass $params) {
  // as with the command above,
  // $params holds everything in the params object of the json-rpc notification message
});

$snapc->connect();
```

By default client will connect with 2 seconds timeout and try to reconnect after 5 seconds if connect failed, connection was dropped or closed by the remote side. This can be changed.

## SharkyDog\Snapcast\Client reference
```php
// wait to reconnect in seconds
public function reconnectInterval(?int $reconn=null): int;
// connect, last non-null timeout is remembered, seconds
public function connect(?int $timeout=null);
// is client currently connecting
public function connecting(): bool;
// is client currently waiting to reconnect
public function reconnecting(): bool;
// is client connected
public function connected(): bool;
// close connection, force means drop connection without waiting for sent data to be flushed
public function disconnect(bool $force=false);
// send command
public function call(string $method, ?\stdClass $params=null, string $id='', int $timeout=10): Promise\PromiseInterface;
```

## TBC
