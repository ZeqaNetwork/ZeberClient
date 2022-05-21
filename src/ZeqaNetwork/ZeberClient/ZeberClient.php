<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient;

use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use Socket;
use function igbinary_serialize;
use function igbinary_unserialize;

class ZeberClient{

    private ZeberSocketThread $thread;
    private Socket $ipcMainSocket;
    private Socket $ipcThreadSocket;
    private ZeberPacketHandler $handler;

    public function __construct(
        private string $serverName,
        private string $ip,
        private int $port
    ) {
        $this->handler = new ZeberPacketHandler($this);
        $server = Server::getInstance();
        $notifier = new SleeperNotifier();

        $server->getTickSleeper()->addNotifier($notifier, function() {
            try{
                $this->readPackets();
            } catch(\Throwable $t) {
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
            new \Threaded(),
            new \Threaded(),
            $this->ipcThreadSocket,
            $notifier,
            $this->serverName
        );
        $this->thread->start(PTHREADS_INHERIT_NONE);
    }

    public function setHandler(ZeberPacketHandler $handler): void{
        $this->handler = $handler;
    }

    public function getThread(): ZeberSocketThread{
        return $this->thread;
    }

    private function readPackets() {
        while(($packet = $this->thread->readPacket()) !== null) {
            try{
                $x = igbinary_unserialize($packet);
                $this->handlePacket($x);
            }catch(\Throwable $t) {
                Server::getInstance()->getLogger()->logException($t);
            }
        }
    }

    private function handlePacket(array $packet) {
        $this->handler->handle($packet["id"], $packet["data"]);
    }

    public function sendPacket(array $packet) {
        // validate packet
        if(!isset($packet["id"], $packet["data"])) {
            throw new ZeberException("Invalid Packet");
        }
        $this->putPacket(igbinary_serialize($packet));
    }

    public function putPacket(string $buffer) {
        $this->thread->writePacket($buffer);
        @socket_write($this->ipcMainSocket, "\x00");
    }

    public function reconnect() {
        $this->thread->reconnect();
        @socket_write($this->ipcMainSocket, "\x00");
    }

    public function close() {
        $this->thread->stop();
        @socket_set_block($this->ipcMainSocket);
        @socket_write($this->ipcMainSocket, "\x00");
        $this->thread->quit();
        @socket_close($this->ipcMainSocket);
        @socket_close($this->ipcThreadSocket);
    }

    public function getIp(): string{
        return $this->ip;
    }

    public function getPort(): int{
        return $this->port;
    }
}