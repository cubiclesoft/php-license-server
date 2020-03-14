<?php
	// Client SDK for the PHP-based License Server.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	class LicenseServer
	{
		protected $fp, $debug;

		public function __construct()
		{
			$this->fp = false;
			$this->debug = false;
		}

		public function __destruct()
		{
			$this->Disconnect();
		}

		public function SetDebug($debug)
		{
			$this->debug = (bool)$debug;
		}

		public function Connect($host = "127.0.0.1", $port = 24276)
		{
			$context = stream_context_create();

			$this->fp = @stream_socket_client("tcp://" . $host . ":" . $port, $errornum, $errorstr, 3, STREAM_CLIENT_CONNECT, $context);
			if ($this->fp === false)  return array("success" => false, "error" => self::LSTranslate("Unable to connect to the license server.  Try again later."), "errorcode" => "connect_failed");

			return array("success" => true);
		}

		public function Disconnect()
		{
			if ($this->fp !== false)
			{
				fclose($this->fp);

				$this->fp = false;
			}
		}

		public function VerifySerial($serialnum, $productid, $majorver, $userinfo, $mode = false, $log = false)
		{
			$data = array(
				"action" => "verify_serial",
				"serial_num" => (string)$serialnum,
				"pid" => (int)$productid,
				"ver" => (int)$majorver,
				"userinfo" => (string)$userinfo
			);

			if (is_string($mode))  $data["mode"] = $mode;
			if (is_string($log))  $data["log"] = $log;

			return $this->RunAPI($data);
		}

		// Revoking a license should be very rare due to increased RAM usage (e.g. piracy).
		public function RevokeLicense($serialnum, $productid, $majorver, $userinfo, $reason, $log = false)
		{
			$data = array(
				"action" => "revoke_restore_license",
				"serial_num" => (string)$serialnum,
				"pid" => (int)$productid,
				"ver" => (int)$majorver,
				"userinfo" => (string)$userinfo,
				"reason" => (string)$reason
			);

			if (is_string($log))  $data["log"] = $log;

			return $this->RunAPI($data);
		}

		public function RestoreLicense($serialnum, $productid, $majorver, $userinfo, $log = false)
		{
			$data = array(
				"action" => "revoke_restore_license",
				"serial_num" => (string)$serialnum,
				"pid" => (int)$productid,
				"ver" => (int)$majorver,
				"userinfo" => (string)$userinfo
			);

			if (is_string($log))  $data["log"] = $log;

			return $this->RunAPI($data);
		}

		// This can also be used to update a license (e.g. 'max_activations' and 'max_downloads').
		public function CreateLicense($productid, $majorver, $userinfo, $options = array())
		{
			$data = array(
				"action" => "create_license",
				"pid" => (int)$productid,
				"major_ver" => (int)$majorver,
				"userinfo" => (string)$userinfo
			);

			foreach ($options as $key => $val)  $data[$key] = $val;

			return $this->RunAPI($data);
		}

		public static function GetUserOrderNumber($prefix, $created, $ordernum)
		{
			if ($ordernum < 1)  return false;

			return preg_replace('/[0-9]+/', "", $prefix) . ((int)($created / 600)) . "-" . sprintf("%04d", $ordernum);
		}

		public static function ExtractOrderNumberInfo($userordernum)
		{
			$str = trim($userordernum);
			$zero = ord("0");
			$nine = ord("9");
			$y = strlen($str);
			for ($x = 0; $x < $y; $x++)
			{
				$tempchr = ord($str[$x]);
				if ($tempchr >= $zero && $tempchr <= $nine)  break;
			}

			$prefix = substr($str, 0, $x);
			$info = explode("-", trim(substr($str, $x)));

			if (count($info) != 2)  return false;

			$created = (int)($info[0] * 600);
			$ordernum = (int)$info[1];

			return array("prefix" => $prefix, "created" => $created, "order_num" => $ordernum);
		}

		public function GetRevokedLicenses($productid = -1, $majorver = -1)
		{
			$data = array(
				"action" => "get_licenses",
				"revoked_only" => true
			);

			if ($productid > -1)  $data["pid"] = (int)$productid;
			if ($majorver > -1)  $data["ver"] = (int)$majorver;

			return $this->RunAPI($data);
		}

		public function GetLicenses($serialnum = false, $userinfo = false, $productid = -1, $majorver = -1, $options = array())
		{
			$data = array(
				"action" => "get_licenses",
			);

			if (is_string($serialnum))  $data["serial_num"] = $serialnum;
			if (is_string($userinfo))  $data["userinfo"] = $userinfo;
			if ($productid > -1)  $data["pid"] = (int)$productid;
			if ($majorver > -1)  $data["ver"] = (int)$majorver;

			foreach ($options as $key => $val)  $data[$key] = $val;

			return $this->RunAPI($data);
		}

		public function AddHistory($serialnum, $productid, $majorver, $userinfo, $type, $log)
		{
			$data = array(
				"action" => "add_history",
				"serial_num" => (string)$serialnum,
				"pid" => (int)$productid,
				"ver" => (int)$majorver,
				"userinfo" => (string)$userinfo,
				"type" => (string)$type,
				"log" => (string)$log
			);

			return $this->RunAPI($data);
		}

		public function GetHistoryByID($id)
		{
			$data = array(
				"action" => "get_history",
				"id" => $id
			);

			return $this->RunAPI($data);
		}

		public function GetHistory($serialnum = false, $userinfo = false, $productid = -1, $majorver = -1, $type = false)
		{
			$data = array(
				"action" => "get_history",
			);

			if (is_string($serialnum))  $data["serial_num"] = $serialnum;
			if (is_string($userinfo))  $data["userinfo"] = $userinfo;
			if ($productid > -1)  $data["pid"] = (int)$productid;
			if ($majorver > -1)  $data["ver"] = (int)$majorver;
			if (is_string($type))  $data["type"] = $type;

			return $this->RunAPI($data);
		}

		// The server supports $info keys of:  'product_classes', 'max_activations', 'max_downloads', and 'encode_chars'.
		public function SetMajorVersion($productid, $majorver, $active = true, $info = array())
		{
			$data = array(
				"action" => "set_major_ver",
				"pid" => (int)$productid,
				"ver" => (int)$majorver,
				"active" => (bool)$active,
				"info" => (array)$info
			);

			return $this->RunAPI($data);
		}

		public function GetMajorVersions($productid, $downloadableonly = false, $secrets = false)
		{
			$data = array(
				"action" => "get_major_vers",
				"pid" => (int)$productid,
			);

			if ($downloadableonly)  $data["downloadable"] = true;
			if ($secrets)  $data["secrets"] = true;

			return $this->RunAPI($data);
		}

		public function DeleteProduct($productid)
		{
			$data = array(
				"action" => "delete_product",
				"id" => (int)$productid
			);

			return $this->RunAPI($data);
		}

		public function CreateProduct($name, $productid = -1)
		{
			$data = array(
				"action" => "create_product",
				"name" => $name,
				"id" => (int)$productid
			);

			return $this->RunAPI($data);
		}

		public function GetProducts()
		{
			$data = array(
				"action" => "get_products"
			);

			return $this->RunAPI($data);
		}

		protected function RunAPI($data)
		{
			if ($this->fp === false)  return array("success" => false, "error" => self::LSTranslate("Not connected to the license server."), "errorcode" => "not_connected");

			// Send the request.
			$data = json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
			$result = @fwrite($this->fp, $data);
			if ($this->debug)
			{
				echo "------- RAW SEND START -------\n";
				echo substr($data, 0, $result);
				echo "------- RAW SEND END -------\n\n";
			}
			if ($result < strlen($data))  return array("success" => false, "error" => self::LSTranslate("Failed to complete sending request to the license server."), "errorcode" => "service_request_failed");

			// Wait for the response.
			$data = @fgets($this->fp);
			if ($this->debug)
			{
				echo "------- RAW RECEIVE START -------\n";
				echo $data;
				echo "------- RAW RECEIVE END -------\n\n";
			}
			$data = @json_decode($data, true);
			if (!is_array($data) || !isset($data["success"]))  return array("success" => false, "error" => self::LSTranslate("Unable to decode the response from the license server."), "errorcode" => "decoding_failed");

			return $data;
		}

		protected static function LSTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>