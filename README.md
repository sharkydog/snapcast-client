# snapcast-client
Snapcast JSON-RPC api wrapper in PHP

This library provides basic connectivity to the [Snapcast](https://github.com/badaix/snapcast) server using its [JSON-RPC api](https://github.com/badaix/snapcast/blob/develop/doc/json_rpc_api/control.md) and a raw TCP socket.
Commands and responses are linked using [ReactPHP promises](https://github.com/reactphp/promise/tree/3.x), notifications are available as [EventEmitter](https://github.com/igorw/evenement/tree/v3.0.2) events.

The client class has an automatic reconnect feature which can be adjusted or stopped during runtime.

## Usage

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

### SharkyDog\Snapcast\Client reference
```php
// wait to reconnect in seconds, 0 to disable reconnects
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

### Events
`event` [`parameter1`, `parameter2`, ...]
- `connect` - connect attempt is being made
- `reconnect` - connect attempt will be made after reconnect interval
- `error-connect` [`\Throwable $error`] - connect error
- `open` - connection established
- `close` [`bool $remote`] - connection closed, `$remote = true` when closed by remote side or dropped
- `notify` [`string $method`, `\stdClass $params`] - notification

Notifications will also be emitted with method as event and params as single parameter, like: `Stream.OnUpdate` [`\stdClass $params`]

## Helpers
There is an abstract class (`SharkyDog\Snapcast\ClientApp`) that helps create additional and more specific logic by binding events to class methods.

### Restore player stream
`SharkyDog\Snapcast\App\RestorePlayerStream` is one such class.
It will monitor the snapcast server for players being added to or removed from groups and will remember the set stream when a player is alone in a group.
Then, when the player is removed from a group and is placed alone in a new group, the stream will be restored to the remembered one or to a configured default per player.
```php
use SharkyDog\Snapcast;
$snapc = new Snapcast\Client('192.168.0.123', 1705);
$plapp = new Snapcast\App\RestorePlayerStream(__DIR__.'/data');
$plapp->client($snapc);
$snapc->connect();
```
Data directory provided in the constructor is where player state is saved and where config is read from.
After client has been set and it connects, `RestorePlayerStream` will start monitoring the server for stream changes and will save the last used stream for every player when it is alone in a group, but will not do anything else until some configuration is done in the data directory.

For every player there will be a file like `stat_name_id`, ex.: `stat_Snapclient_on_Server_aa_bb_cc_dd_ee_ff`. Filename is formed from player name and id after non alphanumeric characters are replaced with underscore. If player name is not set, used name for the stat filename will be `noname`. Content of this file is the last saved stream id.

In the same directory, a config file will be read when the player is removed from a group. These files must be created and managed by you, `RestorePlayerStream` will not write or delete them.

The config file is per player and can be named `conf_name_id` or `conf_id` or `conf_name` or `conf_default`. `RestorePlayerStream` will look for them in that order.
The file `conf_default` would be picked last for any player, but obviously can also be picked by a player with name or id "default".
- If a file is not present, nothing will be done.
- If content of the file is empty, player stream will be restored to the one in the stat file.
- If the file is not empty, its content will be used as the stream to restore.

If you want the player to be restored to the last used stream:
```bash
touch conf_name_id
```

If you want to set the current saved stream as default:
```bash
cat stat_name_id > conf_name_id
```
