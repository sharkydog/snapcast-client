<?php
namespace SharkyDog\Snapcast\App;
use SharkyDog\Snapcast\ClientApp;
use SharkyDog\Snapcast\Log;

class RestorePlayerStream extends ClientApp {
  protected static $notifications = ['Server.OnUpdate','Group.OnStreamChanged','Client.OnNameChanged'];
  private $_datadir;
  private $_players = [];

  public function __construct(string $dataDir) {
    $this->_datadir = str_replace(['/','\\'],DIRECTORY_SEPARATOR,rtrim($dataDir,'/\\').'/');

    if(!is_dir($this->_datadir)) {
      throw new \Exception($this->_datadir.' does not exist');
    }
    if(!is_writable($this->_datadir)) {
      throw new \Exception($this->_datadir.' is not writable');
    }
  }

  private function _fnStat($id, $name) {
    return $this->_datadir.'stat_'.($name ?: 'noname').'_'.$id;
  }

  private function _fnConf($id, $name) {
    clearstatcache();

    if(is_file($file = $this->_datadir.'conf_'.($name ?: 'noname').'_'.$id)) {
      return $file;
    }
    if(is_file($file = $this->_datadir.'conf_'.$id)) {
      return $file;
    }
    if($name && is_file($file = $this->_datadir.'conf_'.$name)) {
      return $file;
    }

    return null;
  }

  private function _fileGlob($pattern, $flags=0) {
    if(($r = @glob($pattern, $flags)) === false) {
      Log::warning('glob() failed on '.$pattern, 'snapc','app','fglob');
    }
    return $r ?: [];
  }

  private function _fileDelete($file) {
    if(($r = @unlink($file)) === false) {
      Log::warning('Can not delete file '.$file, 'snapc','app','fdel');
    }
    return $r;
  }

  private function _fileWrite($file, $data) {
    if(($r = @file_put_contents($file, $data)) === false) {
      Log::warning('Can not write file '.$file, 'snapc','app','fwr');
    }
    return $r;
  }

  private function _fileRead($file) {
    if(($r = @file_get_contents($file)) === false) {
      Log::warning('Can not read file '.$file, 'snapc','app','frd');
    }
    return $r;
  }

  private function _fileRename($fileold, $filenew) {
    if(($r = @rename($fileold, $filenew)) === false) {
      Log::warning('Can not rename file '.$fileold, 'snapc','app','fmv');
    }
    return $r;
  }

  private function _updateState($groups) {
    $foundPlayers = [];

    foreach($groups as $group) {
      $grouped = count($group->clients) > 1;

      foreach($group->clients as $client) {
        $id = preg_replace('/[^a-z0-9]+/i', '_', $client->id);
        $name = preg_replace('/[^a-z0-9]+/i', '_', $client->config->name);
        $foundPlayers[] = $id;

        if(!isset($this->_players[$id])) {
          $this->_players[$id] = [
            'gid' => $group->id,
            'name' => $name,
            'stream' => $group->stream_id,
            'grouped' => $grouped
          ];

          $this->_fileWrite($this->_fnStat($id, $name), $group->stream_id);
          continue;
        }

        if($this->_players[$id]['grouped'] && !$grouped) {
          $stream = ($file = $this->_fnConf($id, $name)) ? $this->_fileRead($file) : null;
          $stream = $stream === '' ? $this->_fileRead($this->_fnStat($id, $name)) : $stream;

          if($stream && $stream != $group->stream_id) {
            $this->call('Group.SetStream', (object)[
              'id' => $group->id,
              'stream_id' => $stream
            ])->then(function($result) use($id) {
              $name = $this->_players[$id]['name'];
              $this->_players[$id]['stream'] = $result->stream_id;
              $this->_fileWrite($this->_fnStat($id, $name), $result->stream_id);
            })->catch(function($e) {
              Log::error('Group.SetStream: ('.$e->getCode().') '.$e->getMessage());
            });
          }
        }

        $this->_players[$id]['gid'] = $group->id;
        $this->_players[$id]['stream'] = $group->stream_id;
        $this->_players[$id]['grouped'] = $grouped;
      }
    }

    if(!empty($this->_players)) {
      foreach(array_diff(array_keys($this->_players), $foundPlayers) as $id) {
        if($file = $this->_fileGlob($this->_datadir.'stat_*_'.$id)[0] ?? null) {
          $this->_fileDelete($file);
        }
        unset($this->_players[$id]);
      }
    }
  }

  protected function Open() {
    foreach($this->_fileGlob($this->_datadir.'stat_*') as $file) {
      $this->_fileDelete($file);
    }

    $this->call('Server.GetStatus')->then(function($result) {
      $this->_updateState($result->server->groups);
    });
  }

  protected function Close($remote) {
    $this->_players = [];
  }

  protected function ServerOnUpdate($params) {
    $this->_updateState($params->server->groups);
  }

  protected function GroupOnStreamChanged($params) {
    $player = array_filter($this->_players, function($player) use($params) {
      return !$player['grouped'] && $params->id == $player['gid'];
    });

    if(empty($player)) {
      return;
    }

    $id = key($player);
    $player = current($player);

    $this->_fileWrite($this->_fnStat($id, $player['name']), $params->stream_id);
    $this->_players[$id]['stream'] = $params->stream_id;
  }

  protected function ClientOnNameChanged($params) {
    $id = preg_replace('/[^a-z0-9]+/i', '_', $params->id);
    $name = preg_replace('/[^a-z0-9]+/i', '_', $params->name);

    if(!isset($this->_players[$id])) {
      Log::warning('Client.OnNameChanged: Player '.$name.'_'.$id.' not found', 'snapc','app','player');
      return;
    }

    $this->_fileRename($this->_fnStat($id, $this->_players[$id]['name']), $this->_fnStat($id, $name));
    $this->_players[$id]['name'] = $name;
  }
}
