<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class LoginBuilder{

	public static function create(string $name, int $type){
		return PacketBuilder::create(PacketId::LOGIN, [
			"name" => $name,
			"type" => $type
		]);
	}
}