# ZeberClient

### Usage
- Connecting
```php
$serverName = "AS1-Practice";
$ip = "127.0.0.1";
$port = 5770;
$zeber = new ZeberClient($serverName, $ip, $port);
```
- Sending Packet
```php
/** @var ZeberClient $zeber */
$zeber->sendPacket(
    ForwardBuilder::create($serverName, "AS2-Practice", [
        "action" => "broadcast_message",
        "message" => "hi everyone!"
    ])
);
```
- Handling Packet
```php
/** @var ZeberClient $zeber */
$handler = new MyZeberPacketHandler($zeber);
$zeber->setHandler($handler);
```
```php
class MyZeberPacketHandler extends \ZeqaNetwork\ZeberClient\ZeberPacketHandler{

    public function handle(string $id, mixed $data){
        switch($id) {
            case PacketId::FORWARD:
                switch($data["action"]) {
                    case "broadcast_message":
                        $message = $data["message"];
                        Server::getInstance()->broadcastMessage($message);
                        break;
                }
                break;
        }
    }
}
```
- Close
```php
/** @var ZeberClient $zeber */
$zeber->close();
```