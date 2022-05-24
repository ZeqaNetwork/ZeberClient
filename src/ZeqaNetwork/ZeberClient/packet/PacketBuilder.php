<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class PacketBuilder{

	public static function create(string $id, mixed $data){
		return [
			"id" => $id,
			"data" => $data
		];
	}
}