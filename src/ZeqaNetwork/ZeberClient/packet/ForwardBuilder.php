<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class ForwardBuilder{

    public static function create(string $from, string $target, mixed $payload) {
        return PacketBuilder::create(PacketId::FORWARD, [
            "from" => $from,
            "target" => $target,
            "payload" => $payload
        ]);
    }
}