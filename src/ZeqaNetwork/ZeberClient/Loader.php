<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient;

use pocketmine\plugin\PluginBase;

class Loader extends PluginBase{

	private ZeberClient $zeber;

	/**
	 * @throws ZeberException
	 */
	public function onEnable() : void{
		//$this->zeber = new ZeberClient($this, "test", "127.0.0.1", 5770); // testing
	}

	public function onDisable() : void{
		//$this->zeber->close();
	}
}