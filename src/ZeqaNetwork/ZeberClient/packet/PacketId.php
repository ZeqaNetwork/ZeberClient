<?php

declare(strict_types=1);

namespace ZeqaNetwork\ZeberClient\packet;

class PacketId{

	// Login Information (name, server or proxy)
	const LOGIN = "login";
	// Forward packet to another client
	const FORWARD = "forward";
	// Request to the Zeber
	const REQUEST = "request";
	// Response from request packet
	const RESPONSE = "response";
	// Auth status
	const AUTH = "auth";
}