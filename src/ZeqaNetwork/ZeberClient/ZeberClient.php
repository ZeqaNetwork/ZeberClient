<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use Socket;
use Threaded;
use Throwable;
use ZeqaNetwork\ZeberClient\packet\PacketId;
use ZeqaNetwork\ZeberClient\packet\RequestBuilder;
use function igbinary_serialize;
use function igbinary_unserialize;
use function socket_close;
use function socket_create_pair;
use function socket_last_error;
use function socket_set_block;
use function socket_set_nonblock;
use function socket_strerror;
use function socket_write;
use function trim;

class ZeberClient{

	private ZeberSocketThread $thread;
	private Socket $ipcMainSocket;
	private Socket $ipcThreadSocket;
	private ZeberPacketHandler $handler;
	private int $nextReqId = 0;
	/** @var callable[] */
	private array $requestCallbacks = [];

	public function __construct(
		private PluginBase $plugin,
		private string $serverName,
		private string $parentServer,
		private string $ip,
		private int $port
	){
		$this->handler = new ZeberPacketHandler($this);
		$server = Server::getInstance();
		$notifier = new SleeperNotifier();

		$server->getTickSleeper()->addNotifier($notifier, function(){
			try{
				$this->readPackets();
			}catch(Throwable $t){
				Server::getInstance()->getLogger()->logException($t);
			}
		});

		$ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
		if(!$ret){
			$err = socket_last_error();
			if(($err !== SOCKET_EPROTONOSUPPORT and $err !== SOCKET_ENOPROTOOPT) or !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)){
				throw new ZeberException('Failed to open IPC socket: ' . trim(socket_strerror(socket_last_error())));
			}
		}

		[$this->ipcMainSocket, $this->ipcThreadSocket] = $ipc;
		socket_set_nonblock($this->ipcMainSocket);

		$this->thread = new ZeberSocketThread(
			$server->getLogger(),
			$ip,
			$port,
			new Threaded(),
			new Threaded(),
			$this->ipcThreadSocket,
			$notifier,
			$this->serverName,
			$this->parentServer
		);
		$this->thread->start(PTHREADS_INHERIT_NONE);
	}

	public function setHandler(ZeberPacketHandler $handler) : void{
		$this->handler = $handler;
	}

	public function getThread() : ZeberSocketThread{
		return $this->thread;
	}

	private function readPackets(){
		while(($packet = $this->thread->readPacket()) !== null){
			try{
				$x = igbinary_unserialize($packet);
				$this->handlePacket($x);
			}catch(Throwable $t){
				Server::getInstance()->getLogger()->logException($t);
			}
		}
	}

	private function handlePacket(array $packet){
		$id = $packet["id"];
		$data = $packet["data"];
		switch($id){
			case PacketId::RESPONSE:
				$resId = (int) $data["id"];
				$payload = $data["payload"];
				if(isset($this->requestCallbacks[$resId])){
					try{
						($this->requestCallbacks[$resId])($payload);
					}finally{
						unset($this->requestCallbacks[$resId]);
					}
				}
				break;
		}
		$this->handler->handle($id, $data);
	}

	public function sendPacket(array $packet){
		// validate packet
		if(!isset($packet["id"], $packet["data"])){
			throw new ZeberException("Invalid Packet");
		}
		$this->putPacket(igbinary_serialize($packet));
	}

	public function putPacket(string $buffer){
		$this->thread->writePacket($buffer);
		@socket_write($this->ipcMainSocket, "\x00");
	}

	public function reconnect(){
		$this->thread->reconnect();
		@socket_write($this->ipcMainSocket, "\x00");
	}

	public function close(){
		$this->thread->stop();
		@socket_set_block($this->ipcMainSocket);
		@socket_write($this->ipcMainSocket, "\x00");
		$this->thread->quit();
		@socket_close($this->ipcMainSocket);
		@socket_close($this->ipcThreadSocket);
	}

	public function getServerName() : string{
		return $this->serverName;
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function sendRequest(string $action, array $payload, callable $callback){
		$id = $this->nextReqId++;
		$this->sendPacket(RequestBuilder::create($id, ["action" => $action] + $payload));
		$this->requestCallbacks[$id] = $callback;
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($id, $action) : void{
			if(isset($this->requestCallbacks[$id])){
				Server::getInstance()->getLogger()->error("Request timeout ID: $id, action: " . $action);
				unset($this->requestCallbacks[$id]); // timeout
			}
		}), 20 * 15);
	}
}