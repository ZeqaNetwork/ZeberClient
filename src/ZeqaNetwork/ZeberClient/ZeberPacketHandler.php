<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient;

class ZeberPacketHandler{

	public function __construct(
		protected ZeberClient $client
	){
	}

	public function handle(string $id, mixed $data){ }
}