<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class LoginBuilder{

	public static function create(string $name, string $parent, int $type){
		return PacketBuilder::create(PacketId::LOGIN, [
			"name" => $name,
			"parent" => $parent,
			"type" => $type
		]);
	}
}