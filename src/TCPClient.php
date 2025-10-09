<?php
namespace SharkyDog\Snapcast;
use React\EventLoop\Loop;
use React\Socket\TcpConnector;
use React\Socket\TimeoutConnector;
use Evenement\EventEmitter;

class TCPClient extends EventEmitter {
  private $_addr;
  private $_port;
  private $_reconn = 5;
  private $_reconn_timer;
  private $_tcpc;
  private $_conn;
  private $_conn_tmo = 2;
  private $_closing = false;

  public function __construct(string $addr, int $port) {
    $this->_addr = $addr;
    $this->_port = $port;
  }

  public function reconnectInterval(?int $reconn=null): int {
    if($reconn !== null) {
      $this->_reconn = max(0, $reconn);
    }
    return $this->_reconn;
  }

  public function connect(?int $timeout=null) {
    if($this->_tcpc || $this->_conn) {
      return;
    }

    if($this->_reconn_timer) {
      Loop::cancelTimer($this->_reconn_timer);
      $this->_reconn_timer = null;
    }

    if($timeout !== null) {
      $this->_conn_tmo = max(0, $timeout);
    }

    $this->_tcpc = new TcpConnector;

    if($this->_conn_tmo) {
      $this->_tcpc = new TimeoutConnector($this->_tcpc, $this->_conn_tmo);
    }

    $uri = 'tcp://'.$this->_addr.':'.$this->_port;
    Log::debug('Connect: '.$uri, 'tcpc','conn');
    $pr = $this->_tcpc->connect($uri);

    $pr->catch(function($e) {
      Log::debug('Error connect: '.$e->getMessage(), 'tcpc','errconn');
      $this->_tcpc = null;
      $this->onErrorConnect($e);
      $this->_reConnect();
    })->then(function($conn) {
      $this->_tcpc = null;
      $this->_conn = $conn;
      $this->_onOpen();
    });

    $this->onConnect();
  }

  public function connecting(): bool {
    return !!$this->_tcpc;
  }

  public function reconnecting(): bool {
    return !!$this->_reconn_timer;
  }

  public function connected(): bool {
    return !!$this->_conn;
  }

  public function disconnect(bool $force=false) {
    if(!$this->_conn) {
      return;
    }

    $this->_closing = true;

    if($force) {
      $this->_conn->close();
    } else {
      $this->_conn->end();
    }
  }

  public function send(string $data) {
    if(!$this->_conn || $this->_closing) {
      return;
    }
    $this->_conn->write($data);
  }

  private function _reConnect() {
    if($this->_tcpc || $this->_closing || !$this->_reconn) {
      return;
    }

    $this->_reconn_timer = Loop::addTimer($this->_reconn, function() {
      $this->_reconn_timer = null;
      $this->connect();
    });

    $this->onReConnect();
  }

  private function _onOpen() {
    Log::debug('Connected', 'tcpc','open');

    $this->_conn->on('close', function() {
      $this->_onClose();
    });

    $this->_conn->on('data', function($data) {
      $this->_onData($data);
    });

    $this->onOpen();
  }

  private function _onClose() {
    Log::debug('Closed'.(!$this->_closing ? ' by remote' : ''), 'tcpc','close');

    $this->_conn = null;
    $this->onClose(!$this->_closing);

    $this->_reConnect();
    $this->_closing = false;
  }

  private function _onData($data) {
    $this->onData($data);
  }

  protected function onConnect() {
    $this->emit('connect');
  }

  protected function onReConnect() {
    $this->emit('reconnect');
  }

  protected function onErrorConnect(\Throwable $e) {
    $this->emit('error-connect', [$e]);
  }

  protected function onOpen() {
    $this->emit('open');
  }

  protected function onClose(bool $remote) {
    $this->emit('close', [$remote]);
  }

  protected function onData(string $data) {
    $this->emit('data', [$data]);
  }
}
