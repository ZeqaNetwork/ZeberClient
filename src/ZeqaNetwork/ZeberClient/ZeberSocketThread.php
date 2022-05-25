<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient;

use AttachableThreadedLogger;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use pocketmine\utils\Binary;
use pocketmine\utils\TextFormat;
use Socket;
use Threaded;
use Throwable;
use ZeqaNetwork\ZeberClient\packet\LoginBuilder;
use ZeqaNetwork\ZeberClient\packet\PacketId;
use function gc_enable;
use function igbinary_serialize;
use function igbinary_unserialize;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function sleep;
use function socket_clear_error;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_last_error;
use function socket_read;
use function socket_select;
use function socket_set_nonblock;
use function socket_strerror;
use function socket_write;
use function strlen;
use function substr;
use function zlib_decode;
use function zlib_encode;
use const AF_INET;
use const SOCK_STREAM;
use const SOCKET_EWOULDBLOCK;
use const SOL_TCP;
use const ZLIB_ENCODING_RAW;

class ZeberSocketThread extends Thread{

	const MTU_SIZE = 65535;

	/** @var Socket|resource|null */
	private $socket;

	private string $pendingWrite = "";
	private string $pendingBuffer = "";
	private bool $connected = false;
	private bool $stop = false;
	private bool $mustReconnect = false;
	private bool $authenticated = false;

	public function __construct(private AttachableThreadedLogger $logger, private string $host, private int $port, private Threaded $packetToWrite, private Threaded $packetToRead, private $ipcSocket, private SleeperNotifier $notifier, private string $serverName, private string $parentServer){
	}

	public function reconnect(){
		$this->mustReconnect = true;
	}

	public function isSafeStopped(){
		return $this->synchronized(function() : bool{
			return $this->stop;
		});
	}

	protected function onRun() : void{
		gc_enable();
		$this->connect();
		while(!$this->isSafeStopped()){
			try{
				if($this->mustReconnect){
					$this->mustReconnect = false;
					$this->close();
					$this->logger->info("Reconnecting...");
					continue;
				}
				if($this->socket === null){
					sleep(5);
					while(!$this->connect() && !$this->stop){
						$this->logger->info("Reconnecting...");
						sleep(5);
					}
				}
				$r = [$this->socket];
				$r[-1] = $this->ipcSocket;
				$w = null;
				if($this->pendingWrite !== ""){
					$w = [$this->socket];
				}
				$e = null;
				if(socket_select($r, $w, $e, 5) > 0){
					foreach($r as $k => $socket){
						if($k === -1){
							@socket_read($socket, 65535); // ipc socket
						}else{
							$this->read();
						}
					}
					if($w !== null){
						foreach($w as $ignored){
							$this->processWrite();
						}
					}
					if($this->isConnected() && $this->authenticated){
						while(($packet = $this->readPacketToWrite()) !== null){
							$this->write(igbinary_unserialize($packet));
						}
					}
				}
			}catch(Throwable $e){
				$this->logger->logException($e);
				$this->close();
			}
		}
		$this->logger->info("ZeberClient thread stopped");
	}

	public function stop(){
		$this->synchronized(function(){
			$this->stop = true;
		});
	}

	/**
	 * @throws ZeberException
	 */
	private function processWrite(){
		while(($buffer = substr($this->pendingWrite, 0, self::MTU_SIZE)) !== ""){
			if(!@socket_write($this->socket, $buffer)){
				$err = socket_last_error($this->socket);
				socket_clear_error($this->socket);
				if($err === SOCKET_EWOULDBLOCK){
					break;
				}
				throw new ZeberException(socket_strerror($err));
			}
			$this->pendingWrite = substr($this->pendingWrite, strlen($buffer));
		}
	}

	/**
	 * @throws ZeberException
	 */
	private function write(array $data){
		$json = json_encode($data);
		if($json === false){
			throw new ZeberException("Failed to json encode: " . json_last_error_msg());
		}
		$compressed = zlib_encode($json, ZLIB_ENCODING_RAW, 7);
		$this->pendingWrite .= Binary::writeInt(strlen($compressed)) . $compressed;
		$this->processWrite();
	}

	private function isConnected() : bool{
		return $this->connected;
	}

	/**
	 * @throws ZeberException
	 */
	private function read(){
		$buf = @socket_read($this->socket, 65535);
		if($buf === false){
			throw new ZeberException("Connection error: " . socket_strerror(socket_last_error($this->socket)));
		}
		if($buf === ""){
			throw new ZeberException("Disconnected");
		}
		$this->pendingBuffer .= $buf;
		$this->readBuffer();
	}

	/**
	 * @throws ZeberException
	 */
	private function readBuffer(){
		$buf = $this->pendingBuffer;
		$len = strlen($buf);
		$offset = 0;
		while($offset < $len){
			$l = Binary::readInt(substr($buf, $offset, 4));
			$compressed = substr($buf, $offset + 4, $l);
			if(strlen($compressed) !== $l){
				break;
			}
			$offset += 4 + $l;
			$packet = zlib_decode($compressed);
			if($packet === false){
				throw new ZeberException("Packet Decompression Error");
			}
			$dec = json_decode($packet, true);
			if($dec === false){
				throw new ZeberException("JSON encode error: " . json_last_error_msg());
			}
			$this->handlePacket($dec);
		}
		$this->pendingBuffer = substr($buf, $offset);
	}

	private function sendPacketToMainThread(string $buffer){
		$this->synchronized(function() use ($buffer) : void{
			$this->packetToRead[] = $buffer;
			$this->notifier->wakeupSleeper();
		});
	}

	public function readPacket() : ?string{
		return $this->synchronized(function() : ?string{
			return $this->packetToRead->shift();
		});
	}

	public function readPacketToWrite() : ?string{
		return $this->synchronized(function() : ?string{
			return $this->packetToWrite->shift();
		});
	}

	public function writePacket(string $buffer){
		$this->synchronized(function() use ($buffer) : void{
			$this->packetToWrite[] = $buffer;
		});
	}

	private function connect() : bool{
		try{
			$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			if(!@socket_connect($socket, $this->host, $this->port)){
				throw new ZeberException("Failed to connect " . $this->host . "/" . $this->port . ": " . socket_strerror(socket_last_error($socket)));
			}
			socket_set_nonblock($socket);
			$this->pendingBuffer = "";
			$this->pendingWrite = "";
			$this->socket = $socket;
			$this->connected = true;

			$this->write($this->getLoginPacket());
			return true;
		}catch(Throwable $e){
			$this->logger->logException($e);
			$this->close();
		}
		return false;
	}

	private function getLoginPacket() : array{
		return LoginBuilder::create($this->serverName, $this->parentServer, 0);
	}

	private function close(){
		if($this->stop){
			return;
		}
		$this->logger->info("Disconnected");
		if($this->socket !== null){
			@socket_close($this->socket);
			$this->socket = null;
		}
		$this->pendingBuffer = "";
		$this->pendingWrite = "";
		$this->connected = false;
		$this->authenticated = false;
	}

	public function quit() : void{
		$this->close();
		parent::quit();
	}

	public function getThreadName() : string{
		return "ZeberSocketThread";
	}

	private function handlePacket(array $dec){
		if(!$this->authenticated){
			if($dec["id"] === PacketId::AUTH){
				if($dec["data"]){
					$this->authenticated = true;
					$this->logger->info(TextFormat::GREEN . "Authenticated to ZeberServer");
				}else{
					throw new ZeberException("Authentication failed");
				}
			}
			return;
		}
		$this->sendPacketToMainThread(igbinary_serialize($dec));
	}
}