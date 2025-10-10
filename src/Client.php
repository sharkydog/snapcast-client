<?php
namespace SharkyDog\Snapcast;
use React\EventLoop\Loop;
use React\Promise;

class Client extends TCPClient {
  private $_buffer = '';
  private $_msg_id = 0;
  private $_msg_sent = [];

  public function __construct(string $addr, int $port) {
    parent::__construct($addr,$port);
  }

  public function send(string $data) {
  }

  protected function onOpen() {
    parent::onOpen();
  }

  protected function onClose(bool $remote) {
    $e = new \Exception('Connection closed');

    foreach($this->_msg_sent as $id => $msg) {
      $this->_rejectMsg($id, $e);
    }

    $this->_msg_sent = [];
    $this->_msg_id = 0;

    parent::onClose($remote);
  }

  protected function onData(string $data, ?\stdClass $json=null) {
    if(!$json) {
      $this->_buffer .= $data;

      if(!preg_match('/^(.+?)\r?\n/', $this->_buffer, $m)) {
        return;
      }

      if($jsonArr = json_decode($m[1])) {
        if(!is_array($jsonArr)) {
          $jsonArr = [$jsonArr];
        }
        foreach($jsonArr as $json) {
          $this->onData('', $json);
        }
      }

      if($this->_buffer = substr($this->_buffer, strlen($m[0]))) {
        $this->onData('');
      }

      return;
    }

    if(($id = $json->id??null) === null) {
      if(!($json->method??null)) {
        return;
      }
      $this->onNotification($json->method, $json->params ?? (object)[]);
      return;
    } else if(!isset($this->_msg_sent[$id])) {
      return;
    }

    if($error = $json->error??null) {
      $this->_rejectMsg($id, new \Exception($json->error->message, $json->error->code));
      return;
    }

    if(!($result = $json->result??null)) {
      $this->_rejectMsg($id, new \Exception('Malformed response missing result'));
      return;
    }

    $def = $this->_msg_sent[$id]['def'];
    Loop::cancelTimer($this->_msg_sent[$id]['tmr']);
    unset($this->_msg_sent[$id]);

    $def->resolve($result);
  }

  protected function onNotification(string $method, \stdClass $params) {
    $this->emit('notify', [$method,$params]);
    $this->emit($method, [$params]);
  }

  private function _rejectMsg($id, $e) {
    if(!isset($this->_msg_sent[$id])) {
      return;
    }

    $def = $this->_msg_sent[$id]['def'];
    Loop::cancelTimer($this->_msg_sent[$id]['tmr']);
    unset($this->_msg_sent[$id]);

    $def->reject($e);
  }

  public function call(string $method, ?\stdClass $params=null, string $id='', int $timeout=10): Promise\PromiseInterface {
    if(!$this->connected()) {
      return Promise\reject(new \Exception('Not connected'));
    }

    if(!$id) {
      if($this->_msg_id == 0xffffffff) $this->_msg_id = 0;
      $id = $this->_msg_id + 1;
    }

    if(isset($this->_msg_sent[$id])) {
      return Promise\reject(new \Exception('Duplicate message id'));
    }

    $this->_msg_id++;
    $timeout = max(1, $timeout);

    $msg = (object)[
      'id' => $id,
      'jsonrpc' => '2.0',
      'method' => $method
    ];

    if($params) {
      $msg->params = $params;
    }

    parent::send(json_encode($msg)."\n");

    $this->_msg_sent[$id] = [
      'def' => new Promise\Deferred,
      'tmr' => Loop::addTimer($timeout, function() use($id) {
        $this->_rejectMsg($id, new \Exception('Timeout'));
      })
    ];

    return $this->_msg_sent[$id]['def']->promise();
  }
}
