<?php
namespace SharkyDog\Snapcast;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;

class EventEmitterProxy extends EventEmitter {
  private $_emitter;
  private $_listeners = [];

  public function __construct(?EventEmitterInterface $emitter=null) {
    if($emitter) {
      $this->emitter($emitter);
    }
  }

  private function _on($event, $listener, $func) {
    parent::$func($event, $listener);

    if(!isset($this->_listeners[$event])) {
      $this->_listeners[$event] = function(...$args) use($event) {
        $this->emit($event, $args);
        $this->_clearListener($event);
      };
      if($this->_emitter) {
        $this->_emitter->on($event, $this->_listeners[$event]);
      }
    }
  }

  private function _clearListener($event) {
    if($this->hasListeners($event) || !isset($this->_listeners[$event])) {
      return;
    }
    if($this->_emitter) {
      $this->_emitter->removeListener($event, $this->_listeners[$event]);
    }
    unset($this->_listeners[$event]);
  }

  public function emitter(?EventEmitterInterface $emitter=null): ?EventEmitterInterface {
    if($emitter !== null && !$this->_emitter) {
      $this->_emitter = $emitter;
      foreach($this->_listeners as $event => $listener) {
        $this->_emitter->on($event, $listener);
      }
    }
    return $this->_emitter;
  }

  public function on($event, callable $listener) {
    $this->_on($event, $listener, 'on');
  }

  public function once($event, callable $listener) {
    $this->_on($event, $listener, 'once');
  }

  public function removeListener($event, callable $listener) {
    parent::removeListener($event, $listener);
    $this->_clearListener($event);
  }

  public function removeAllListeners($event = null) {
    parent::removeAllListeners($event);
    foreach($event ? [$event] : array_keys($this->_listeners) as $event) {
      $this->_clearListener($event);
    }
  }

  public function hasListeners($event, ?string $func=null): bool {
    $r = (!$func || $func=='on') && !empty($this->listeners[$event]);
    $r = $r || ((!$func || $func=='once') && !empty($this->onceListeners[$event]));
    return $r;
  }
}
