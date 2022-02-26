<?php
	// CubicleSoft PHP GenericServer class.
	// (C) 2020 CubicleSoft.  All Rights Reserved.

	// Make sure PHP doesn't introduce weird limitations.
	ini_set("memory_limit", "-1");
	set_time_limit(0);

	class GenericServer
	{
		protected $debug, $fp, $ssl, $initclients, $clients, $nextclientid;
		protected $defaulttimeout, $defaultclienttimeout, $lasttimeoutcheck;

		public function __construct()
		{
			$this->Reset();
		}

		public function Reset()
		{
			$this->debug = false;
			$this->fp = false;
			$this->ssl = false;
			$this->initclients = array();
			$this->clients = array();
			$this->nextclientid = 1;

			$this->defaulttimeout = 30;
			$this->defaultclienttimeout = 30;
			$this->lasttimeoutcheck = microtime(true);
		}

		public function __destruct()
		{
			$this->Stop();
		}

		public function SetDebug($debug)
		{
			$this->debug = (bool)$debug;
		}

		public function SetDefaultTimeout($timeout)
		{
			$this->defaulttimeout = (int)$timeout;
		}

		public function SetDefaultClientTimeout($timeout)
		{
			$this->defaultclienttimeout = (int)$timeout;
		}

		public static function GetSSLCiphers($type = "intermediate")
		{
			$type = strtolower($type);

			// Cipher list last updated May 3, 2017.
			if ($type == "modern")  return "ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256";
			else if ($type == "old")  return "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA:ECDHE-ECDSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:DHE-DSS-AES128-SHA256:DHE-RSA-AES256-SHA256:DHE-DSS-AES256-SHA:DHE-RSA-AES256-SHA:ECDHE-RSA-DES-CBC3-SHA:ECDHE-ECDSA-DES-CBC3-SHA:EDH-RSA-DES-CBC3-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA256:AES256-SHA256:AES128-SHA:AES256-SHA:AES:DES-CBC3-SHA:HIGH:SEED:!aNULL:!eNULL:!EXPORT:!DES:!RC4:!MD5:!PSK:!RSAPSK:!aDH:!aECDH:!EDH-DSS-DES-CBC3-SHA:!KRB5-DES-CBC3-SHA:!SRP";

			return "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:DHE-RSA-AES256-SHA256:DHE-RSA-AES256-SHA:ECDHE-ECDSA-DES-CBC3-SHA:ECDHE-RSA-DES-CBC3-SHA:EDH-RSA-DES-CBC3-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA256:AES256-SHA256:AES128-SHA:AES256-SHA:DES-CBC3-SHA:!DSS";
		}

		// Starts the server on the host and port.
		// $host is usually 0.0.0.0 or 127.0.0.1 for IPv4 and [::0] or [::1] for IPv6.
		public function Start($host, $port, $sslopts = false)
		{
			$this->Stop();

			$context = stream_context_create();

			if (is_array($sslopts))
			{
				stream_context_set_option($context, "ssl", "ciphers", self::GetSSLCiphers());
				stream_context_set_option($context, "ssl", "disable_compression", true);
				stream_context_set_option($context, "ssl", "allow_self_signed", true);
				stream_context_set_option($context, "ssl", "verify_peer", false);

				// 'local_cert' and 'local_pk' are common options.
				foreach ($sslopts as $key => $val)
				{
					stream_context_set_option($context, "ssl", $key, $val);
				}

				$this->ssl = true;
			}

			$this->fp = stream_socket_server("tcp://" . $host . ":" . $port, $errornum, $errorstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
			if ($this->fp === false)  return array("success" => false, "error" => self::GSTranslate("Bind() failed.  Reason:  %s (%d)", $errorstr, $errornum), "errorcode" => "bind_failed");

			// Enable non-blocking mode.
			stream_set_blocking($this->fp, 0);

			return array("success" => true);
		}

		public function Stop()
		{
			if ($this->fp !== false)
			{
				foreach ($this->initclients as $id => $client)
				{
					fclose($client->fp);
				}

				foreach ($this->clients as $id => $client)
				{
					$this->RemoveClient($id);
				}

				fclose($this->fp);

				$this->initclients = array();
				$this->clients = array();
				$this->fp = false;
				$this->ssl = false;
			}

			$this->nextclientid = 1;
		}

		// Dangerous but allows for stream_select() calls on multiple, separate stream handles.
		public function GetStream()
		{
			return $this->fp;
		}

		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
			if ($this->fp !== false)  $readfps[$prefix . "gs_s"] = $this->fp;
			if ($timeout === false || $timeout > $this->defaulttimeout)  $timeout = $this->defaulttimeout;

			foreach ($this->initclients as $id => $client)
			{
				if ($client->mode === "init")
				{
					$readfps[$prefix . "gs_c_" . $id] = $client->fp;
					if ($timeout > 1)  $timeout = 1;
				}
			}
			foreach ($this->clients as $id => $client)
			{
				$readfps[$prefix . "gs_c_" . $id] = $client->fp;

				if ($client->writedata !== "")  $writefps[$prefix . "gs_c_" . $id] = $client->fp;
			}
		}

		// Sometimes keyed arrays don't work properly.
		public static function FixedStreamSelect(&$readfps, &$writefps, &$exceptfps, $timeout)
		{
			// In order to correctly detect bad outputs, no '0' integer key is allowed.
			if (isset($readfps[0]) || isset($writefps[0]) || ($exceptfps !== NULL && isset($exceptfps[0])))  return false;

			$origreadfps = $readfps;
			$origwritefps = $writefps;
			$origexceptfps = $exceptfps;

			$result2 = stream_select($readfps, $writefps, $exceptfps, $timeout);
			if ($result2 === false)  return false;

			if (isset($readfps[0]))
			{
				$fps = array();
				foreach ($origreadfps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($readfps as $num => $fp)
				{
					$readfps[$fps[(int)$fp]] = $fp;

					unset($readfps[$num]);
				}
			}

			if (isset($writefps[0]))
			{
				$fps = array();
				foreach ($origwritefps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($writefps as $num => $fp)
				{
					$writefps[$fps[(int)$fp]] = $fp;

					unset($writefps[$num]);
				}
			}

			if ($exceptfps !== NULL && isset($exceptfps[0]))
			{
				$fps = array();
				foreach ($origexceptfps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($exceptfps as $num => $fp)
				{
					$exceptfps[$fps[(int)$fp]] = $fp;

					unset($exceptfps[$num]);
				}
			}

			return true;
		}

		public function InitNewClient($fp)
		{
			$client = new stdClass();

			$client->id = $this->nextclientid;
			$client->mode = "init";
			$client->readdata = "";
			$client->writedata = "";
			$client->recvsize = 0;
			$client->sendsize = 0;
			$client->lastts = microtime(true);
			$client->fp = $fp;
			$client->ipaddr = stream_socket_get_name($fp, true);

			$this->initclients[$this->nextclientid] = $client;

			$this->nextclientid++;

			return $client;
		}

		protected function HandleNewConnections(&$readfps, &$writefps)
		{
			if (isset($readfps["gs_s"]))
			{
				while (($fp = @stream_socket_accept($this->fp, 0)) !== false)
				{
					// Enable non-blocking mode.
					stream_set_blocking($fp, 0);

					$client = $this->InitNewClient($fp);

					if ($this->debug)  echo "Accepted new connection from '" . $client->ipaddr . "'.  Client ID " . $client->id . ".\n";
				}

				unset($readfps["gs_s"]);
			}
		}

		private static function StreamTimedOut($fp)
		{
			if (!function_exists("stream_get_meta_data"))  return false;

			$info = stream_get_meta_data($fp);

			return $info["timed_out"];
		}

		private function ReadClientData($client)
		{
			$data2 = fread($client->fp, 65536);

			if ($data2 === false)  return array("success" => false, "error" => self::GSTranslate("Underlying stream encountered a read error."), "errorcode" => "stream_read_error");
			if ($data2 === "")
			{
				if (feof($client->fp))  return array("success" => false, "error" => self::GSTranslate("Remote peer disconnected."), "errorcode" => "peer_disconnected");
				if (self::StreamTimedOut($client->fp))  return array("success" => false, "error" => self::GSTranslate("Underlying stream timed out."), "errorcode" => "stream_timeout_exceeded");

				return array("success" => false, "error" => self::GSTranslate("Non-blocking read returned no data."), "errorcode" => "no_data");
			}

			$tempsize = strlen($data2);
			$client->recvsize += $tempsize;

			$client->readdata .= $data2;

			return array("success" => true);
		}

		private function WriteClientData($client)
		{
			if ($client->writedata !== "")
			{
				// Serious bug in PHP core for non-blocking SSL sockets:  https://bugs.php.net/bug.php?id=72333
				if ($this->ssl && version_compare(PHP_VERSION, "7.1.4") <= 0)
				{
					// This is a huge hack that has a pretty good chance of blocking on the socket.
					// Peeling off up to just 4KB at a time helps to minimize that possibility.  It's better than guaranteed failure of the socket though.
					@stream_set_blocking($client->fp, 1);
					$result = fwrite($client->fp, (strlen($client->writedata) > 4096 ? substr($client->writedata, 0, 4096) : $client->writedata));
					@stream_set_blocking($client->fp, 0);
				}
				else
				{
					$result = fwrite($client->fp, $client->writedata);
				}

				if ($result === false || feof($client->fp))  return array("success" => false, "error" => self::GSTranslate("A fwrite() failure occurred.  Most likely cause:  Connection failure."), "errorcode" => "fwrite_failed");

				$data2 = substr($client->writedata, 0, $result);
				$client->writedata = (string)substr($client->writedata, $result);

				$client->sendsize += $result;

				$this->UpdateClientState($client->id);

				if (strlen($client->writedata))  return array("success" => false, "error" => self::GSTranslate("Non-blocking write did not send all data."), "errorcode" => "no_data");
			}

			return array("success" => true);
		}

		// Handles new connections, the initial conversation, basic packet management, rate limits, and timeouts.
		// Can wait on more streams than just sockets and/or more sockets.  Useful for waiting on other resources.
		// 'gs_s' and the 'gs_c_' prefix are reserved.
		// Returns an array of clients that may need more processing.
		public function Wait($timeout = false, $readfps = array(), $writefps = array(), $exceptfps = NULL)
		{
			$this->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

			$result = array("success" => true, "clients" => array(), "removed" => array(), "readfps" => array(), "writefps" => array(), "exceptfps" => array());
			if (!count($readfps) && !count($writefps))  return $result;

			$result2 = self::FixedStreamSelect($readfps, $writefps, $exceptfps, $timeout);
			if ($result2 === false)  return array("success" => false, "error" => self::GSTranslate("Wait() failed due to stream_select() failure.  Most likely cause:  Connection failure."), "errorcode" => "stream_select_failed");

			// Return handles that were being waited on.
			$result["readfps"] = $readfps;
			$result["writefps"] = $writefps;
			$result["exceptfps"] = $exceptfps;

			$this->ProcessWaitResult($result);

			return $result;
		}

		protected function ProcessWaitResult(&$result)
		{
			// Handle new connections.
			$this->HandleNewConnections($result["readfps"], $result["writefps"]);

			// Handle clients in the read queue.
			foreach ($result["readfps"] as $cid => $fp)
			{
				if (!is_string($cid) || strlen($cid) < 6 || substr($cid, 0, 5) !== "gs_c_")  continue;

				$id = (int)substr($cid, 5);

				if (!isset($this->clients[$id]))  continue;

				$client = $this->clients[$id];

				$client->lastts = microtime(true);

				$result2 = $this->ReadClientData($client);
				if ($result2["success"])
				{
					// Let the caller know there is probably data to handle.
					$result["clients"][$id] = $client;
				}
				else if ($result2["errorcode"] !== "no_data")
				{
					if ($this->debug)  echo "Read failed for client ID " . $client->id . ".\n";

					$result["removed"][$id] = array("result" => $result2, "client" => $client);

					$this->RemoveClient($id);
				}

				unset($result["readfps"][$cid]);
			}

			// Handle clients in the write queue.
			foreach ($result["writefps"] as $cid => $fp)
			{
				if (!is_string($cid) || strlen($cid) < 6 || substr($cid, 0, 5) !== "gs_c_")  continue;

				$id = (int)substr($cid, 5);

				if (!isset($this->clients[$id]))  continue;

				$client = $this->clients[$id];

				$client->lastts = microtime(true);

				$result2 = $this->WriteClientData($client);
				if ($result2["success"])
				{
					// Let the caller add more data to the write buffer.
					$result["clients"][$id] = $client;
				}
				else if ($result2["errorcode"] !== "no_data")
				{
					if ($this->debug)  echo "Write failed for client ID " . $client->id . ".\n";

					$result["removed"][$id] = array("result" => $result2, "client" => $client);

					$this->RemoveClient($id);
				}

				unset($result["writefps"][$cid]);
			}

			// Initialize new clients.
			foreach ($this->initclients as $id => $client)
			{
				do
				{
					$origmode = $client->mode;

					switch ($client->mode)
					{
						case "init":
						{
							$result2 = ($this->ssl ? stream_socket_enable_crypto($client->fp, true, STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER) : true);

							if ($result2 === true)
							{
								$client->mode = "main";

								if ($this->debug)  echo "Switched to 'main' state for client ID " . $id . ".\n";

								$client->lastts = microtime(true);

								$this->clients[$id] = $client;
								unset($this->initclients[$id]);

								$result["clients"][$id] = $client;
							}
							else if ($result2 === false)
							{
								if ($this->debug)  echo "Unable to initialize crypto for client ID " . $id . ".\n";

								fclose($client->fp);

								unset($this->initclients[$id]);
							}

							break;
						}
					}
				} while (isset($this->initclients[$id]) && $origmode !== $client->mode);
			}

			// Handle client timeouts.
			$ts = microtime(true);
			if ($this->lasttimeoutcheck <= $ts - 5)
			{
				foreach ($this->clients as $id => $client)
				{
					if ($client->lastts + $this->defaultclienttimeout < $ts)
					{
						if ($this->debug)  echo "Client ID " . $id . " timed out.  Removing.\n";

						$result2 = array("success" => false, "error" => self::GSTranslate("Client timed out.  Most likely cause:  Connection failure."), "errorcode" => "client_timeout");

						$result["removed"][$id] = array("result" => $result2, "client" => $client);

						$this->RemoveClient($id);
					}
				}

				$this->lasttimeoutcheck = $ts;
			}
		}

		public function GetClients()
		{
			return $this->clients;
		}

		public function NumClients()
		{
			return count($this->clients);
		}

		public function UpdateClientState($id)
		{
		}

		public function GetClient($id)
		{
			return (isset($this->clients[$id]) ? $this->clients[$id] : false);
		}

		public function DetachClient($id)
		{
			if (!isset($this->clients[$id]))  return false;

			$client = $this->clients[$id];

			unset($this->clients[$id]);

			return $client;
		}

		public function RemoveClient($id)
		{
			if (isset($this->clients[$id]))
			{
				$client = $this->clients[$id];

				if ($client->fp !== false)  fclose($client->fp);

				unset($this->clients[$id]);
			}
		}

		public static function GSTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>