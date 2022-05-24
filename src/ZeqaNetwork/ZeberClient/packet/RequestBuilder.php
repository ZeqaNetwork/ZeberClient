<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class RequestBuilder{

	public static function create(int $id, mixed $payload){
		return PacketBuilder::create(PacketId::REQUEST, [
			"id" => $id,
			"payload" => $payload
		]);
	}
}