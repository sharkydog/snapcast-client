<?php
namespace SharkyDog\Snapcast;
use React\Promise;

abstract class ClientApp {
  protected static $notifications = [];
  private $_client;
  private $_emitter;

  public function client(?Client $client=null, bool $throw=true): ?Client {
    if($client !== null && !$this->_client) {
      $this->_client = $client;
      $this->emitter()->emitter($client);
      $this->_init();
    }
    if($throw && !$this->_client) {
      throw new \Exception('Client not set');
    }
    return $this->_client;
  }

  private function _init() {
    if(method_exists($this, 'Open')) {
      $this->emitter()->on('open', function() {
        $this->Open();
      });
    }

    if(method_exists($this, 'Close')) {
      $this->emitter()->on('close', function($remote) {
        $this->Close($remote);
      });
    }

    foreach(static::$notifications as $method) {
      $func = str_replace('.','',$method);

      if(!method_exists($this, $func)) {
        Log::warning($method.' declared, but '.$func.' not defined in '.get_class($this), 'snapc','app');
        continue;
      }

      $this->emitter()->on($method, function($params) use($func) {
        $this->$func($params);
      });
    }
  }

  protected function emitter(): EventEmitterProxy {
    if(!$this->_emitter) {
      $this->_emitter = new EventEmitterProxy;
    }
    return $this->_emitter;
  }

  protected function call(string $method, ?\stdClass $params=null, string $id='', int $timeout=10): Promise\PromiseInterface {
    if(!$this->_client) {
      return Promise\reject(new \Exception('Client not set'));
    }
    return $this->_client->call($method, $params, $id, $timeout);
  }
}
