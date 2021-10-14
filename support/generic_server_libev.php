<?php
	// CubicleSoft PHP GenericServer class with libev support.
	// (C) 2020 CubicleSoft.  All Rights Reserved.

	if (!class_exists("GenericServer", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/generic_server.php";

	class LibEvGenericServer extends GenericServer
	{
		protected $ev_watchers, $ev_read_ready, $ev_write_ready;

		public static function IsSupported()
		{
			$os = php_uname("s");
			$windows = (strtoupper(substr($os, 0, 3)) == "WIN");

			return (extension_loaded("ev") && !$windows);
		}

		public function Reset()
		{
			parent::Reset();

			$this->ev_watchers = array();
		}

		public function Internal_LibEvHandleEvent($watcher, $revents)
		{
			if ($revents & Ev::READ)  $this->ev_read_ready[$watcher->data] = $watcher->fd;
			if ($revents & Ev::WRITE)  $this->ev_write_ready[$watcher->data] = $watcher->fd;
		}

		public function Start($host, $port, $sslopts = false)
		{
			$result = parent::Start($host, $port, $sslopts);
			if (!$result["success"])  return $result;

			$this->ev_watchers["gs_s"] = new EvIo($this->fp, Ev::READ, array($this, "Internal_LibEvHandleEvent"), "gs_s");

			return $result;
		}

		public function Stop()
		{
			parent::Stop();

			foreach ($this->ev_watchers as $key => $watcher)
			{
				$watcher->stop();
			}

			$this->ev_watchers = array();
		}

		public function InitNewClient($fp)
		{
			$client = parent::InitNewClient($fp);

			$this->ev_watchers["gs_c_" . $client->id] = new EvIo($client->fp, Ev::READ, array($this, "Internal_LibEvHandleEvent"), "gs_c_" . $client->id);

			return $client;
		}

		public function Internal_LibEvTimeout($watcher, $revents)
		{
			Ev::stop(Ev::BREAK_ALL);
		}

		public function Wait($timeout = false, $readfps = array(), $writefps = array(), $exceptfps = NULL)
		{
			if ($timeout === false || $timeout > $this->defaulttimeout)  $timeout = $this->defaulttimeout;

			if ($timeout > 1 && count($this->initclients))  $timeout = 1;

			$result = array("success" => true, "clients" => array(), "removed" => array(), "readfps" => array(), "writefps" => array(), "exceptfps" => array());
			if (!count($this->ev_watchers) && !count($readfps) && !count($writefps))  return $result;

			$this->ev_read_ready = array();
			$this->ev_write_ready = array();

			// Temporarily attach other read/write handles.
			$tempwatchers = array();

			foreach ($readfps as $key => $fp)
			{
				$tempwatchers[] = new EvIo($fp, Ev::READ, array($this, "Internal_LibEvHandleEvent"), $key);
			}

			foreach ($writefps as $key => $fp)
			{
				$tempwatchers[] = new EvIo($fp, Ev::WRITE, array($this, "Internal_LibEvHandleEvent"), $key);
			}

			$tempwatchers[] = new EvTimer($timeout, 0, array($this, "Internal_LibEvTimeout"));

			// Wait for one or more events to fire.
			Ev::run(Ev::RUN_ONCE);

			// Remove temporary watchers.
			foreach ($tempwatchers as $watcher)  $watcher->stop();

			// Return handles that were being waited on.
			$result["readfps"] = $this->ev_read_ready;
			$result["writefps"] = $this->ev_write_ready;
			$result["exceptfps"] = (is_array($exceptfps) ? array() : $exceptfps);

			$this->ProcessWaitResult($result);

			// Post-process clients.
			foreach ($result["clients"] as $id => $client)
			{
				$this->UpdateClientState($id);
			}

			return $result;
		}

		public function UpdateClientState($id)
		{
			if (isset($this->clients[$id]))
			{
				$client = $this->clients[$id];

				$events = Ev::READ;

				if ($client->writedata !== "")  $events |= Ev::WRITE;

				$this->ev_watchers["gs_c_" . $id]->set($client->fp, $events);
			}
		}

		public function RemoveClient($id)
		{
			parent::RemoveClient($id);

			if (isset($this->ev_watchers["gs_c_" . $id]))
			{
				$this->ev_watchers["gs_c_" . $id]->stop();

				unset($this->ev_watchers["gs_c_" . $id]);
			}
		}
	}
?>