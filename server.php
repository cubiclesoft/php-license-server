<?php
	// PHP-based License Server.
	// (C) 2020 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";

	// Service Manager integration.
	if ($argc > 1)
	{
		require_once $rootpath . "/servicemanager/sdks/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = "php-license-server";

		if ($argv[1] == "install")
		{
			// Verify root on *NIX.
			if (function_exists("posix_geteuid"))
			{
				$uid = posix_geteuid();
				if ($uid !== 0)  CLI::DisplayError("The installer must be run as the 'root' user (UID = 0) to install the system service on *NIX hosts.");

				// Create the system user/group.
				ob_start();
				system("useradd -r -s /bin/false " . escapeshellarg("php-license-server"));
				$output = ob_get_contents() . "\n";
				ob_end_clean();
			}

			// Make sure the database is readable by the user.
			@chmod($rootpath . "/license.db", 0664);
			if (function_exists("posix_geteuid"))  @chgrp($rootpath . "/license.db", "php-license-server");

			// Install the service.
			$args = array();
			$options = array(
				"nixuser" => "php-license-server",
				"nixgroup" => "php-license-server"
			);

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else
		{
			CLI::DisplayError("Command not recognized.  Run service manager directly for anything other than 'install' and 'uninstall'.");
		}

		exit();
	}

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";
	require_once $rootpath . "/support/str_basics.php";
	require_once $rootpath . "/support/random.php";
	require_once $rootpath . "/support/serial_number.php";
	require_once $rootpath . "/support/generic_server.php";
	require_once $rootpath . "/support/generic_server_libev.php";

	// Initialize the SQLite database connection.
	try
	{
		$db = new CSDB_sqlite();

		$db->Connect("sqlite:" . $rootpath . "/license.db");
	}
	catch (Exception $e)
	{
		CLI::DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
	}

	// Create database tables.
	if (!$db->TableExists("products"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("products", array(
				"id" => array("INTEGER", 2, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true),
				"name" => array("STRING", 1, 255, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
			)));
		}
		catch (Exception $e)
		{
			CLI::DisplayError("Unable to create the database table 'products'.  " . $e->getMessage());
		}
	}

	if (!$db->TableExists("versions"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("versions", array(
				"pid" => array("INTEGER", 2, "UNSIGNED" => true, "NOT NULL" => true),
				"major_ver" => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
				"encrypt_secret" => array("STRING", 1, 255, "NOT NULL" => true),
				"validate_secret" => array("STRING", 1, 255, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"active" => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
				"info" => array("STRING", 3, "NOT NULL" => true),
			),
			array(
				array("PRIMARY", array("pid", "major_ver"), "NAME" => "versions_pid_major_ver"),
			)));
		}
		catch (Exception $e)
		{
			CLI::DisplayError("Unable to create the database table 'versions'.  " . $e->getMessage());
		}
	}

	if (!$db->TableExists("licenses"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("licenses", array(
				"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
				"serial_num" => array("STRING", 1, 50, "NOT NULL" => true),
				"pid" => array("INTEGER", 2, "UNSIGNED" => true, "NOT NULL" => true),
				"major_ver" => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
				"userinfo" => array("STRING", 1, 255, "NOT NULL" => true),
				"order_num" => array("INTEGER", 3, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"lastused" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"info" => array("STRING", 3, "NOT NULL" => true),
			),
			array(
				array("KEY", array("serial_num"), "NAME" => "licenses_serial"),
				array("KEY", array("pid", "major_ver"), "NAME" => "licenses_pid_major_ver"),
				array("KEY", array("userinfo"), "NAME" => "licenses_userinfo"),
				array("KEY", array("created", "order_num"), "NAME" => "licenses_created_order_num"),
			)));
		}
		catch (Exception $e)
		{
			CLI::DisplayError("Unable to create the database table 'licenses'.  " . $e->getMessage());
		}
	}

	if (!$db->TableExists("revoked_licenses"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("revoked_licenses", array(
				"lid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"reason" => array("STRING", 1, 255, "NOT NULL" => true),
			)));
		}
		catch (Exception $e)
		{
			CLI::DisplayError("Unable to create the database table 'revoked_licenses'.  " . $e->getMessage());
		}
	}

	if (!$db->TableExists("history"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("history", array(
				"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
				"lid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"type" => array("STRING", 1, 50, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"info" => array("STRING", 3, "NOT NULL" => true),
			),
			array(
				array("KEY", array("lid", "type"), "NAME" => "history_lid_type"),
			)));
		}
		catch (Exception $e)
		{
			CLI::DisplayError("Unable to create the database table 'history'.  " . $e->getMessage());
		}
	}

	// Load all products.
	$products = array();

	$result = $db->Query("SELECT", array(
		"*",
		"FROM" => "products",
		"ORDER BY" => "id"
	));

	while ($row = $result->NextRow())
	{
		$products[(int)$row->id] = array(
			"name" => $row->name,
			"created" => (int)$row->created,
			"versions" => array()
		);
	}

	// Load all major versions.
	$result = $db->Query("SELECT", array(
		"*",
		"FROM" => "versions",
		"ORDER BY" => "pid, major_ver"
	));

	while ($row = $result->NextRow())
	{
		$products[(int)$row->pid]["versions"][(int)$row->major_ver] = array(
			"encrypt_secret" => pack("H*", $row->encrypt_secret),
			"validate_secret" => pack("H*", $row->validate_secret),
			"created" => (int)$row->created,
			"active" => (bool)$row->active,
			"info" => json_decode($row->info, true),
			"revoked" => array()
		);
	}

	// Load all revoked licenses.
	$result = $db->Query("SELECT", array(
		"r.*, l.serial_num, l.pid, l.major_ver, l.userinfo",
		"FROM" => "revoked_licenses AS r, licenses AS l",
		"WHERE" => "r.lid = l.id"
	));

	while ($row = $result->NextRow())
	{
		$products[(int)$row->pid]["versions"][(int)$row->major_ver]["revoked"][$row->serial_num] = array(
			"userinfo" => $row->userinfo,
			"created" => (int)$row->created,
			"reason" => $row->reason
		);
	}

	// Retrieve the licenses created within the current 10 minute window.
	$orderts = (int)(time() / 600) * 600;
	$orders = array();

	$result = $db->Query("SELECT", array(
		"order_num",
		"FROM" => "licenses",
		"WHERE" => "created >= ? AND created < ? AND order_num > 0"
	), $orderts, $orderts + 600);

	while ($row = $result->NextRow())
	{
		$orders[(int)$row->order_num] = true;
	}

	// License-specific APIs support appending to an audit log.
	function AddToHistory(&$result2, $lid, $type, &$data)
	{
		global $db;

		if (isset($data["log"]) && is_string($data["log"]))
		{
			$logts = time();

			$db->Query("INSERT", array("history", array(
				"lid" => $lid,
				"type" => $type,
				"created" => $logts,
				"info" => $data["log"]
			), "AUTO INCREMENT" => "id"));

			$result2["log_id"] = $db->GetInsertID();
			$result2["log_type"] = $type;
			$result2["log_ts"] = $logts;
		}
	}

	function MakeLicensePassword()
	{
		global $rng, $freqmap;

		$words = array();
		for ($x = 0; $x < 4; $x++)  $words[] = preg_replace('/[^a-z]/', "-", strtolower($rng->GenerateWord($freqmap, $rng->GetInt(4, 6))));

		return implode("-", $words);
	}

	$rng = new CSPRNG();
	$freqmap = json_decode(file_get_contents($rootpath . "/support/en_us_freq_3.json"), true);

	// Start the server.
	echo "Starting server" . (LibEvGenericServer::IsSupported() ? " with PECL libev support" : "") . "...\n";
	$gs = (LibEvGenericServer::IsSupported() ? new LibEvGenericServer() : new GenericServer());
//	$gs->SetDebug(true);
	$result = $gs->Start("127.0.0.1", 24276);
	if (!$result["success"])  CLI::DisplayError($result);

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		$result = $gs->Wait(3);
		if (!$result["success"])  break;

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if (!isset($client->appdata))
			{
				echo "Client " . $id . " connected.\n";

				$client->appdata = array();
			}

			while (($pos = strpos($client->readdata, "\n")) !== false)
			{
				$data = substr($client->readdata, 0, $pos);
				$client->readdata = substr($client->readdata, $pos + 1);

				echo "Client " . $id . ", received:  " . $data . "\n";
				$data = @json_decode($data, true);
				if (is_array($data) && isset($data["action"]))
				{
					if ($data["action"] === "verify_serial")
					{
						// Decrypt and verify a serial number.
						if (!isset($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!is_string($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else if (!isset($data["ver"]))  $result2 = array("success" => false, "error" => "Missing major version number.", "errorcode" => "missing_ver");
						else if (!isset($products[(int)$data["pid"]]["versions"][(int)$data["ver"]]))  $result2 = array("success" => false, "error" => "Major version does not exist for the product.", "errorcode" => "product_major_ver_not_found");
						else if (!isset($data["userinfo"]))  $result2 = array("success" => false, "error" => "Missing user info.", "errorcode" => "missing_userinfo");
						else if (!is_string($data["userinfo"]))  $result2 = array("success" => false, "error" => "Invalid user info.  Expected a string.", "errorcode" => "invalid_userinfo");
						else
						{
							$pid = (int)$data["pid"];
							$ver = (int)$data["ver"];
							$vinfo = &$products[$pid]["versions"][$ver];

							// If the license has been revoked, then return that info.
							if (!$vinfo["active"])  $result2 = array("success" => false, "error" => "The specified version of this product has been deactivated.", "errorcode" => "version_deactivated");
							else if (isset($vinfo["revoked"][$data["serial_num"]]))  $result2 = array("success" => false, "error" => "Invalid serial number.", "errorcode" => "invalid_serial", "info" => $vinfo["revoked"][$data["serial_num"]]["reason"]);
							else
							{
								$options = array(
									"decrypt_secret" => $vinfo["encrypt_secret"],
									"validate_secret" => $vinfo["validate_secret"],
								);

								if (isset($vinfo["info"]["encode_chars"]))  $options["decode_chars"] = $vinfo["info"]["encode_chars"];

								$result2 = SerialNumber::Verify($data["serial_num"], $pid, $ver, $data["userinfo"], $options);
								if ($result2["success"])
								{
									$serialnum = $result2["serial_num"];

									$result2["product_name"] = $products[$pid]["name"];

									// If there is a product class name mapping, then include it.
									if (isset($vinfo["info"]["product_classes"][$result2["product_class"]]))
									{
										$result2["product_class_name"] = $vinfo["info"]["product_classes"][$result2["product_class"]];
									}

									// Update activations/downloads if desired (e.g. for limiting installs).
									if (isset($data["mode"]))
									{
										try
										{
											$row = $db->GetRow("SELECT", array(
												"*",
												"FROM" => "licenses",
												"WHERE" => "serial_num = ? AND pid = ? AND major_ver = ? AND userinfo = ?"
											), $serialnum, $pid, $ver, $data["userinfo"]);

											if (!$row)  $result2 = array("success" => false, "error" => "No license found.", "errorcode" => "no_license_info");
											else
											{
												$info = json_decode($row->info, true);

												if (!isset($info["activations"]))  $info["activations"] = 0;
												if (!isset($info["downloads"]))  $info["downloads"] = 0;

												if ($data["mode"] === "activate")
												{
													if (isset($info["max_activations"]) && $info["activations"] >= $info["max_activations"])  $result2 = array("success" => false, "error" => "Too many activations.", "errorcode" => "too_many_activations");
													else if (!isset($info["max_activations"]) && isset($vinfo["info"]["max_activations"]) && $info["activations"] >= $vinfo["info"]["max_activations"])  $result2 = array("success" => false, "error" => "Too many activations.", "errorcode" => "too_many_activations");
													else
													{
														$info["activations"]++;

														if (!$db->NumTransactions())  $db->BeginTransaction();

														$db->Query("UPDATE", array("licenses", array(
															"lastused" => time(),
															"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
														), "WHERE" => "id = ?"), $row->id);

														$result2["activations"] = $info["activations"];

														AddToHistory($result2, $row->id, "activated", $data);
													}
												}
												else if ($data["mode"] === "deactivate")
												{
													$info["activations"]--;
													if ($info["activations"] < 0)  $info["activations"] = 0;

													if (!$db->NumTransactions())  $db->BeginTransaction();

													$db->Query("UPDATE", array("licenses", array(
														"lastused" => time(),
														"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
													), "WHERE" => "id = ?"), $row->id);

													$result2["activations"] = $info["activations"];

													AddToHistory($result2, $row->id, "deactivated", $data);
												}
												else if ($data["mode"] === "download")
												{
													if (isset($info["max_downloads"]) && $info["downloads"] >= $info["max_downloads"])  $result2 = array("success" => false, "error" => "Too many downloads.", "errorcode" => "too_many_downloads");
													else if (!isset($info["max_downloads"]) && isset($vinfo["info"]["max_downloads"]) && $info["downloads"] >= $vinfo["info"]["max_downloads"])  $result2 = array("success" => false, "error" => "Too many downloads.", "errorcode" => "too_many_downloads");
													else
													{
														$info["downloads"]++;

														if (!$db->NumTransactions())  $db->BeginTransaction();

														$db->Query("UPDATE", array("licenses", array(
															"lastused" => time(),
															"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
														), "WHERE" => "id = ?"), $row->id);

														$result2["downloads"] = $info["downloads"];

														AddToHistory($result2, $row->id, "downloaded", $data);
													}
												}
											}
										}
										catch (Exception $e)
										{
											CLI::DisplayError("Unable to activate the serial number.  " . $e->getMessage(), false, false);

											$result2 = array("success" => false, "error" => "A database exception occurred while validating the serial number.", "errorcode" => "db_exception");
										}
									}
								}
							}
						}
					}
					else if ($data["action"] === "revoke_restore_license")
					{
						// Change the revoked status of a license.
						if (!isset($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!is_string($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else if (!isset($data["ver"]))  $result2 = array("success" => false, "error" => "Missing major version number.", "errorcode" => "missing_ver");
						else if (!isset($products[(int)$data["pid"]]["versions"][(int)$data["ver"]]))  $result2 = array("success" => false, "error" => "Major version does not exist for the product.", "errorcode" => "product_major_ver_not_found");
						else if (!isset($data["userinfo"]))  $result2 = array("success" => false, "error" => "Missing user info.", "errorcode" => "missing_userinfo");
						else if (!is_string($data["userinfo"]))  $result2 = array("success" => false, "error" => "Invalid user info.  Expected a string.", "errorcode" => "invalid_userinfo");
						else
						{
							$pid = (int)$data["pid"];
							$ver = (int)$data["ver"];
							$vinfo = &$products[$pid]["versions"][$ver];

							$options = array(
								"decrypt_secret" => $vinfo["encrypt_secret"],
								"validate_secret" => $vinfo["validate_secret"],
							);

							if (isset($vinfo["info"]["encode_chars"]))  $options["decode_chars"] = $vinfo["info"]["encode_chars"];

							$result2 = SerialNumber::Verify($data["serial_num"], $pid, $ver, $data["userinfo"], $options);
							if ($result2["success"])
							{
								$serialnum = $result2["serial_num"];

								try
								{
									// Find a matching serial.
									$row = $db->GetRow("SELECT", array(
										"*",
										"FROM" => "licenses",
										"WHERE" => "serial_num = ? AND pid = ? AND major_ver = ? AND userinfo = ?"
									), $serialnum, $pid, $ver, $data["userinfo"]);

									if (!$row)  $result2 = array("success" => false, "error" => "No license found.", "errorcode" => "no_license_info");
									else
									{
										if (!$db->NumTransactions())  $db->BeginTransaction();

										$db->Query("DELETE", array("revoked_licenses", "WHERE" => "lid = ?"), $row->id);
										unset($vinfo["revoked"][$data["serial_num"]]);

										if (isset($data["reason"]) && is_string($data["reason"]))
										{
											$ts = time();

											$db->Query("INSERT", array("revoked_licenses", array(
												"lid" => $row->id,
												"created" => $ts,
												"reason" => $data["reason"]
											)));

											$vinfo["revoked"][$data["serial_num"]] = array(
												"userinfo" => $data["userinfo"],
												"created" => $ts,
												"reason" => $data["reason"]
											);

											$result2 = array(
												"success" => true,
												"revoked" => $ts,
												"revoke_reason" => $data["reason"]
											);

											AddToHistory($result2, $row->id, "revoked", $data);
										}
										else
										{
											$result2 = array("success" => true);

											AddToHistory($result2, $row->id, "restored", $data);
										}
									}
								}
								catch (Exception $e)
								{
									CLI::DisplayError("Unable to manage revokation of a license.  " . $e->getMessage(), false, false);

									$result2 = array("success" => false, "error" => "A database exception occurred while managing revokation of the license.", "errorcode" => "db_exception");
								}
							}
						}
					}
					else if ($data["action"] === "create_license")
					{
						// Create a license.
						if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else if (!isset($data["major_ver"]))  $result2 = array("success" => false, "error" => "Missing major version number.", "errorcode" => "missing_major_ver");
						else if (!isset($products[(int)$data["pid"]]["versions"][(int)$data["major_ver"]]))  $result2 = array("success" => false, "error" => "Major version does not exist for the product.", "errorcode" => "product_major_ver_not_found");
						else if (!isset($data["userinfo"]))  $result2 = array("success" => false, "error" => "Missing user info.", "errorcode" => "missing_userinfo");
						else if (!is_string($data["userinfo"]))  $result2 = array("success" => false, "error" => "Invalid user info.  Expected a string.", "errorcode" => "invalid_userinfo");
						else if (!$products[(int)$data["pid"]]["versions"][(int)$data["major_ver"]]["active"])  $result2 = array("success" => false, "error" => "The specified version of this product has been deactivated.", "errorcode" => "version_deactivated");
						else
						{
							$pid = (int)$data["pid"];
							$ver = (int)$data["major_ver"];
							$info = (isset($data["info"]) && is_array($data["info"]) ? $data["info"] : array());

							if (!isset($info["password"]))  $info["password"] = MakeLicensePassword();

							// Calculate the serial number.
							$options = array(
								"encrypt_secret" => $products[$pid]["versions"][$ver]["encrypt_secret"],
								"validate_secret" => $products[$pid]["versions"][$ver]["validate_secret"]
							);

							if (isset($data["expires"]))  $options["expires"] = (bool)$data["expires"];
							if (isset($data["date"]))  $options["date"] = $data["date"];
							if (isset($data["product_class"]))  $options["product_class"] = $data["product_class"];
							if (isset($data["minor_ver"]))  $options["minor_ver"] = $data["minor_ver"];
							if (isset($data["custom_bits"]))  $options["custom_bits"] = $data["custom_bits"];

							$vinfo = &$products[$pid]["versions"][$ver];
							if (isset($vinfo["info"]["encode_chars"]))  $options["encode_chars"] = $vinfo["info"]["encode_chars"];

							$result2 = SerialNumber::Generate($pid, $ver, $data["userinfo"], $options);
							if ($result2["success"])
							{
								$serialnum = $result2["serial"];

								// Verify that the serial number does not exist before creating it.
								try
								{
									$row = $db->GetRow("SELECT", array(
										"*",
										"FROM" => "licenses",
										"WHERE" => "serial_num = ? AND pid = ? AND major_ver = ? AND userinfo = ?"
									), $serialnum, $pid, $ver, $data["userinfo"]);

									if (!$db->NumTransactions())  $db->BeginTransaction();

									if ($row)
									{
										$db->Query("UPDATE", array("licenses", array(
											"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
										), "WHERE" => "id = ?"), $row->id);

										$result2 = array(
											"success" => true,
											"serial_num" => $serialnum,
											"product_id" => $pid,
											"product_name" => $products[$pid]["name"],
											"major_ver" => $ver,
											"userinfo" => $data["userinfo"],
											"serial_info" => SerialNumber::Verify($serialnum, $pid, $ver, $data["userinfo"], $options),
											"created" => (int)$row->created,
											"order_num" => (int)$row->order_num,
											"lastused" => (int)$row->lastused,
											"info" => $info
										);

										AddToHistory($result2, $row->id, "updated_info", $data);
									}
									else
									{
										$ts = time();

										// Calculate the next order number.
										if ($ts >= $orderts + 600)
										{
											$orderts = (int)($ts / 600) * 600;
											$orders = array();
										}

										if (isset($data["order_num"]) && !$data["order_num"])  $ordernum = -1;
										else
										{
											$y = count($orders) * 1.5;
											$x = 9;
											while ($x <= $y)  $x = $x * 10 + 9;
											if ($x < 9999)  $x = 9999;

											// Worst case scenario is a 66% chance of a collision under high load.
											do
											{
												$ordernum = random_int(1, $x);
											} while (isset($orders[$ordernum]));

											$orders[$ordernum] = true;
										}

										$db->Query("INSERT", array("licenses", array(
											"serial_num" => $serialnum,
											"pid" => $pid,
											"major_ver" => $ver,
											"userinfo" => $data["userinfo"],
											"created" => $ts,
											"order_num" => $ordernum,
											"lastused" => 0,
											"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
										), "AUTO INCREMENT" => "id"));

										$id2 = $db->GetInsertID();

										$result2 = array(
											"success" => true,
											"serial_num" => $serialnum,
											"product_id" => $pid,
											"product_name" => $products[$pid]["name"],
											"major_ver" => $ver,
											"userinfo" => $data["userinfo"],
											"serial_info" => SerialNumber::Verify($serialnum, $pid, $ver, $data["userinfo"], $options),
											"created" => $ts,
											"order_num" => $ordernum,
											"lastused" => 0,
											"info" => $info
										);

										AddToHistory($result2, $id2, "created", $data);
									}

									// If there is a product class name mapping, then include it.
									if ($result2["serial_info"]["success"] && isset($vinfo["info"]["product_classes"][$result2["serial_info"]["product_class"]]))
									{
										$result2["serial_info"]["product_class_name"] = $vinfo["info"]["product_classes"][$result2["serial_info"]["product_class"]];
									}
								}
								catch (Exception $e)
								{
									CLI::DisplayError("Unable to create a license.  " . $e->getMessage(), false, false);

									$result2 = array("success" => false, "error" => "A database exception occurred while creating the license.", "errorcode" => "db_exception");
								}
							}
						}
					}
					else if ($data["action"] === "get_licenses")
					{
						// Search and retrieve a list of licenses.
						$result2 = array(
							"success" => true,
							"licenses" => array()
						);

						if (isset($data["revoked_only"]) && (bool)$data["revoked_only"])
						{
							foreach ($products as $pid => &$pinfo)
							{
								if (isset($data["pid"]) && (int)$data["pid"] != $pid)  continue;

								foreach ($pinfo["versions"] as $ver => &$vinfo)
								{
									if (isset($data["ver"]) && (int)$data["ver"] != $ver)  continue;

									$options = array(
										"decrypt_secret" => $vinfo["encrypt_secret"],
										"validate_secret" => $vinfo["validate_secret"],
									);

									if (isset($vinfo["info"]["encode_chars"]))  $options["decode_chars"] = $vinfo["info"]["encode_chars"];

									foreach ($vinfo["revoked"] as $serialnum => $iinfo)
									{
										$license = array(
											"serial_num" => $serialnum,
											"product_id" => $pid,
											"product_name" => $products[$pid]["name"],
											"major_ver" => $ver,
											"active" => $vinfo["active"],
											"userinfo" => $iinfo["userinfo"],
											"serial_info" => SerialNumber::Verify($serialnum, $pid, $ver, $iinfo["userinfo"], $options),
											"revoked" => $iinfo["created"],
											"revoke_reason" => $iinfo["reason"]
										);

										// If there is a product class name mapping, then include it.
										if ($license["serial_info"]["success"] && isset($vinfo["info"]["product_classes"][$license["serial_info"]["product_class"]]))
										{
											$license["serial_info"]["product_class_name"] = $vinfo["info"]["product_classes"][$license["serial_info"]["product_class"]];
										}

										$result2["licenses"][] = $license;
									}
								}
							}
						}
						else
						{
							$sqlwhere = array();
							$sqlvars = array();
							$limit = 25;

							if (isset($data["serial_num"]) && is_string($data["serial_num"]))
							{
								$sqlwhere[] = "serial_num = ?";
								$sqlvars[] = $data["serial_num"];
							}

							// Locate a specific license by order number.
							if (isset($data["created"]) && is_int($data["created"]) && isset($data["order_num"]) && is_int($data["order_num"]) && $data["order_num"] > 0)
							{
								$ts = (int)($data["created"] / 600) * 600;

								$sqlwhere[] = "created >= ? AND created < ? AND order_num = ?";
								$sqlvars[] = $ts;
								$sqlvars[] = $ts + 600;
								$sqlvars[] = $data["order_num"];
							}

							if (isset($data["userinfo"]) && is_string($data["userinfo"]))
							{
								if (isset($data["userinfo_like"]) && (bool)$data["userinfo_like"])
								{
									$sqlwhere[] = "userinfo LIKE ?";
									$sqlvars[] = str_replace(array("_", "%"), array("\\_", "\\%"), $data["userinfo"]) . "%";
								}
								else
								{
									$sqlwhere[] = "userinfo = ?";
									$sqlvars[] = $data["userinfo"];
								}

								$limit = 1000;
							}

							// There's no particularly good reason for allowing retrieval of more than 25 random serial numbers.
							if (isset($data["pid"]) && isset($data["ver"]))
							{
								$sqlwhere[] = "pid = ? AND major_ver = ?";
								$sqlvars[] = (int)$data["pid"];
								$sqlvars[] = (int)$data["ver"];
							}

							if (!count($sqlwhere))  $result2 = array("success" => true, "error" => "Invalid or incomplete search query specified.  Must be restricted to a specific serial number, product ID and major version, and/or user info.", "errorcode" => "invalid_search_query");
							else
							{
								try
								{
									$expirets = time() - 24 * 60 * 60;

									$result3 = $db->Query("SELECT", array(
										"*",
										"FROM" => "licenses",
										"WHERE" => implode(" AND ", $sqlwhere),
										"LIMIT" => $limit
									), $sqlvars);

									if (isset($data["password"]) && !is_string($data["password"]))  unset($data["password"]);

									while ($row = $result3->NextRow())
									{
										$pid = (int)$row->pid;
										$ver = (int)$row->major_ver;

										// Generally impossible unless there is data corruption.  Skip if missing.
										if (!isset($products[$pid]))  continue;

										$vinfo = &$products[$pid]["versions"][$ver];

										$options = array(
											"decrypt_secret" => $vinfo["encrypt_secret"],
											"validate_secret" => $vinfo["validate_secret"],
										);

										if (isset($vinfo["info"]["encode_chars"]))  $options["decode_chars"] = $vinfo["info"]["encode_chars"];

										$license = array(
											"serial_num" => $row->serial_num,
											"product_id" => $pid,
											"product_name" => $products[$row->pid]["name"],
											"major_ver" => $ver,
											"active" => $vinfo["active"],
											"userinfo" => $row->userinfo,
											"serial_info" => SerialNumber::Verify($row->serial_num, $pid, $ver, $row->userinfo, $options),
											"created" => (int)$row->created,
											"order_num" => (int)$row->order_num,
											"lastused" => (int)$row->lastused,
											"info" => json_decode($row->info, true)
										);

										// Check password if supplied.
										if (isset($data["password"]) && (!isset($license["info"]["password"]) || Str::CTstrcmp($license["info"]["password"], $data["password"]) !== 0))  continue;

										// If there is a product class name mapping, then include it.
										if ($license["serial_info"]["success"] && isset($vinfo["info"]["product_classes"][$license["serial_info"]["product_class"]]))
										{
											$license["serial_info"]["product_class_name"] = $vinfo["info"]["product_classes"][$license["serial_info"]["product_class"]];
										}

										// If the license has been revoked, then include that info.
										if (isset($vinfo["revoked"][$row->serial_num]))
										{
											// Skip revoked licenses if desired.
											if (isset($data["revoked"]) && !(bool)$data["revoked"])  continue;

											$iinfo = $vinfo["revoked"][$row->serial_num];

											if ($iinfo["userinfo"] === $row->userinfo)
											{
												$license["revoked"] = $iinfo["created"];
												$license["revoke_reason"] = $iinfo["reason"];
											}
										}

										$result2["licenses"][] = $license;
									}
								}
								catch (Exception $e)
								{
									CLI::DisplayError("Unable to run search.  " . $e->getMessage(), false, false);

									$result2 = array("success" => false, "error" => "A database exception occurred while running the search.", "errorcode" => "db_exception");
								}
							}
						}
					}
					else if ($data["action"] === "add_history")
					{
						// Add a history item to a license.
						if (!isset($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!is_string($data["serial_num"]))  $result2 = array("success" => false, "error" => "Missing serial number.", "errorcode" => "missing_serial_num");
						else if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else if (!isset($data["ver"]))  $result2 = array("success" => false, "error" => "Missing major version number.", "errorcode" => "missing_ver");
						else if (!isset($products[(int)$data["pid"]]["versions"][(int)$data["ver"]]))  $result2 = array("success" => false, "error" => "Major version does not exist for the product.", "errorcode" => "product_major_ver_not_found");
						else if (!isset($data["userinfo"]))  $result2 = array("success" => false, "error" => "Missing user info.", "errorcode" => "missing_userinfo");
						else if (!is_string($data["userinfo"]))  $result2 = array("success" => false, "error" => "Invalid user info.  Expected a string.", "errorcode" => "invalid_userinfo");
						else if (!isset($data["type"]))  $result2 = array("success" => false, "error" => "Missing log type.", "errorcode" => "missing_type");
						else if (!is_string($data["type"]))  $result2 = array("success" => false, "error" => "Invalid log type.  Expected a string.", "errorcode" => "invalid_type");
						else if (!isset($data["log"]))  $result2 = array("success" => false, "error" => "Missing log.", "errorcode" => "missing_log");
						else if (!is_string($data["log"]))  $result2 = array("success" => false, "error" => "Invalid log.  Expected a string.", "errorcode" => "invalid_log");
						else
						{
							$pid = (int)$data["pid"];
							$ver = (int)$data["ver"];
							$vinfo = &$products[$pid]["versions"][$ver];

							$options = array(
								"decrypt_secret" => $vinfo["encrypt_secret"],
								"validate_secret" => $vinfo["validate_secret"],
							);

							if (isset($vinfo["info"]["encode_chars"]))  $options["decode_chars"] = $vinfo["info"]["encode_chars"];

							$result2 = SerialNumber::Verify($data["serial_num"], $pid, $ver, $data["userinfo"], $options);
							if ($result2["success"])
							{
								$serialnum = $result2["serial_num"];

								try
								{
									$row = $db->GetRow("SELECT", array(
										"*",
										"FROM" => "licenses",
										"WHERE" => "serial_num = ? AND pid = ? AND major_ver = ? AND userinfo = ?"
									), $serialnum, $pid, $ver, $data["userinfo"]);

									if (!$row)  $result2 = array("success" => false, "error" => "No license found.", "errorcode" => "no_license_info");
									else
									{
										if (!$db->NumTransactions())  $db->BeginTransaction();

										$result2 = array("success" => true);

										AddToHistory($result2, $row->id, $data["type"], $data);
									}
								}
								catch (Exception $e)
								{
									CLI::DisplayError("Unable to add to history log.  " . $e->getMessage(), false, false);

									$result2 = array("success" => false, "error" => "A database exception occurred while adding an audit log.", "errorcode" => "db_exception");
								}
							}
						}
					}
					else if ($data["action"] === "get_history")
					{
						// Retrieve the history log for one or more licenses.
						$result2 = array(
							"success" => true,
							"entries" => array()
						);

						$sqlwhere = array("h.lid = l.id");
						$sqlvars = array();
						$limit = 100;

						if (isset($data["id"]) && is_numeric($data["id"]))
						{
							$sqlwhere[] = "h.id = ?";
							$sqlvars[] = $data["id"];
						}

						if (isset($data["serial_num"]) && is_string($data["serial_num"]))
						{
							// Normalize the serial number.
							$result3 = SerialNumber::Verify($data["serial_num"]);
							if ($result3["success"])
							{
								$serialnum = $result3["serial_num"];

								$sqlwhere[] = "l.serial_num = ?";
								$sqlvars[] = $serialnum;
							}
						}

						if (isset($data["userinfo"]) && is_string($data["userinfo"]))
						{
							$sqlwhere[] = "l.userinfo = ?";
							$sqlvars[] = $data["userinfo"];
						}

						if (isset($data["pid"]) && isset($data["ver"]))
						{
							$sqlwhere[] = "l.pid = ? AND l.major_ver = ?";
							$sqlvars[] = (int)$data["pid"];
							$sqlvars[] = (int)$data["ver"];
						}

						if (isset($data["type"]) && is_string($data["type"]))
						{
							$sqlwhere[] = "h.type = ?";
							$sqlvars[] = $data["type"];
						}

						$result3 = $db->Query("SELECT", array(
							"h.*, l.serial_num, l.pid, l.major_ver, l.userinfo",
							"FROM" => "history AS h, licenses AS l",
							"WHERE" => implode(" AND ", $sqlwhere),
							"ORDER BY" => "h.id DESC",
							"LIMIT" => $limit
						), $sqlvars);

						while ($row = $result3->NextRow())
						{
							$pid = (int)$row->pid;
							$ver = (int)$row->major_ver;

							// Generally impossible unless there is data corruption.  Skip if missing.
							if (!isset($products[$pid]))  continue;

							$result2["entries"][] = array(
								"id" => $row->id,
								"serial_num" => $row->serial_num,
								"product_id" => $pid,
								"product_name" => $products[$row->pid]["name"],
								"major_ver" => $ver,
								"userinfo" => $row->userinfo,
								"type" => $row->type,
								"created" => $row->created,
								"info" => $row->info
							);
						}
					}
					else if ($data["action"] === "set_major_ver")
					{
						// Creates/updates major version information for a product.
						if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else if (!isset($data["ver"]))  $result2 = array("success" => false, "error" => "Missing major version number.", "errorcode" => "missing_ver");
						else if ((int)$data["ver"] < 0 || (int)$data["ver"] > 255)  $result2 = array("success" => false, "error" => "Invalid major version number (0-255).", "errorcode" => "invalid_ver");
						else
						{
							$pid = (int)$data["pid"];
							$ver = (int)$data["ver"];
							$active = (isset($data["active"]) && (bool)$data["active"]);
							$info = (isset($data["info"]) && is_array($data["info"]) ? $data["info"] : array("product_classes" => array()));
							if (!isset($info["product_classes"]) || !is_array($info["product_classes"]))  $info["product_classes"] = array();

							// Validate product classes.
							foreach ($info["product_classes"] as $num => $name)
							{
								if (!is_int($num) || $num < 0 || $num > 15)  unset($info["product_classes"][$num]);
							}

							// Validate alternate encoding character set.
							if (isset($info["encode_chars"]))
							{
								$result2 = SerialNumber::Verify("", $pid, $ver, "", array("decode_chars" => $info["encode_chars"]));
								if ($result2["errorcode"] === "invalid_decode_chars")  unset($info["encode_chars"]);
							}

							try
							{
								if (!$db->NumTransactions())  $db->BeginTransaction();

								if (!isset($products[$pid]["versions"][$ver]))
								{
									$encryptsecret = $rng->GenerateToken(20);
									$validatesecret = $rng->GenerateToken(20);

									$ts = time();

									$db->Query("INSERT", array("versions", array(
										"pid" => $pid,
										"major_ver" => $ver,
										"encrypt_secret" => $encryptsecret,
										"validate_secret" => $validatesecret,
										"created" => $ts,
										"active" => 1,
										"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
									)));

									$products[$pid]["versions"][$ver] = array(
										"encrypt_secret" => pack("H*", $encryptsecret),
										"validate_secret" => pack("H*", $validatesecret),
										"created" => $ts,
										"active" => $active,
										"info" => $info
									);

									ksort($products[$pid]["versions"]);

									$vinfo = &$products[$pid]["versions"][$ver];
								}
								else
								{
									$vinfo = &$products[$pid]["versions"][$ver];

									// Don't allow the character set to change once licenses have been created using the character set.
									$row = $db->GetRow("SELECT", array(
										"*",
										"FROM" => "licenses",
										"WHERE" => "pid = ? AND major_ver = ?",
										"LIMIT" => 1
									), $pid, $ver);

									if ($row)
									{
										if (isset($vinfo["encode_chars"]))  $info["encode_chars"] = $vinfo["encode_chars"];
										else  unset($info["encode_chars"]);
									}

									$db->Query("UPDATE", array("versions", array(
										"active" => (int)$active,
										"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
									), "WHERE" => "pid = ? AND major_ver = ?"), $pid, $ver);

									$vinfo["active"] = $active;
									$vinfo["info"] = $info;
								}

								$info = $vinfo["info"];
								if (isset($info["product_classes"]))  $info["product_classes"] = (object)$info["product_classes"];

								$result2 = array(
									"success" => true,
									"pid" => $pid,
									"major_ver" => $ver,
									"created" => $vinfo["created"],
									"active" => $vinfo["active"],
									"info" => $info
								);
							}
							catch (Exception $e)
							{
								CLI::DisplayError("Unable to set up the major version.  " . $e->getMessage(), false, false);

								$result2 = array("success" => false, "error" => "A database exception occurred while setting up the major version.", "errorcode" => "db_exception");
							}
						}
					}
					else if ($data["action"] === "get_major_vers")
					{
						// Retrieves major version information for a product.
						if (!isset($data["pid"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_pid");
						else if (!isset($products[(int)$data["pid"]]))  $result2 = array("success" => false, "error" => "Product does not exist.", "errorcode" => "product_not_found");
						else
						{
							$secrets = (isset($data["secrets"]) && (bool)$data["secrets"]);

							$result2 = array(
								"success" => true,
								"pid" => (int)$data["pid"],
								"product_name" => $products[(int)$data["pid"]]["name"],
								"versions" => array()
							);

							foreach ($products[$data["pid"]]["versions"] as $ver => &$vinfo)
							{
								$info = $vinfo["info"];

								if (isset($data["downloadable"]) && (bool)$data["downloadable"] && (!$vinfo["active"] || (isset($vinfo["info"]["max_downloads"]) && $vinfo["info"]["max_downloads"] === 0)))  continue;

								if (isset($info["product_classes"]))  $info["product_classes"] = (object)$info["product_classes"];

								$result2["versions"][$ver] = array(
									"encrypt_secret" => ($secrets ? bin2hex($vinfo["encrypt_secret"]) : false),
									"validate_secret" => ($secrets ? bin2hex($vinfo["validate_secret"]) : false),
									"created" => $vinfo["created"],
									"active" => $vinfo["active"],
									"info" => $info
								);
							}

							$result2["versions"] = (object)$result2["versions"];
						}
					}
					else if ($data["action"] === "delete_product")
					{
						// Deletes a product, all versions, all licenses, and all revoked licenses from the database.
						if (!isset($data["id"]))  $result2 = array("success" => false, "error" => "Missing product ID.", "errorcode" => "missing_id");
						else
						{
							try
							{
								$pid = (int)$data["id"];

								if (!$db->NumTransactions())  $db->BeginTransaction();

								$lids = array();

								$result3 = $db->Query("SELECT", array(
									"r.lid",
									"FROM" => "revoked_licenses AS r, licenses AS l",
									"WHERE" => "r.lid = l.id AND l.pid = ?"
								), $pid);

								while ($row = $result->NextRow())
								{
									$lids[] = $row->id;
								}

								if (count($lids))  $db->Query("DELETE", array("revoked_licenses", "WHERE" => "lid IN (" . implode(",", $lids) . ")"));
								$db->Query("DELETE", array("licenses", "WHERE" => "pid = ?"), $pid);
								$db->Query("DELETE", array("versions", "WHERE" => "pid = ?"), $pid);
								$db->Query("DELETE", array("products", "WHERE" => "id = ?"), $pid);

								unset($products[$pid]);

								$result2 = array("success" => true);
							}
							catch (Exception $e)
							{
								CLI::DisplayError("Unable to delete the product.  " . $e->getMessage(), false, false);

								$result2 = array("success" => false, "error" => "A database exception occurred while deleting the product.", "errorcode" => "db_exception");
							}
						}
					}
					else if ($data["action"] === "create_product")
					{
						// Creates/updates a product.
						if (!isset($data["name"]))  $result2 = array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
						else if (!isset($data["id"]) && count($products) >= 1024)  $result2 = array("success" => false, "error" => "Unable to create product due to too many products.  The serial number generator only supports 1,024 unique product IDs.", "errorcode" => "max_products_reached");
						else if (isset($data["id"]) && (int)$data["id"] > 1023)  $result2 = array("success" => false, "error" => "Product ID outside valid range.", "errorcode" => "invalid_id");
						else
						{
							if (isset($data["id"]) && (int)$data["id"] >= 0)  $pid = (int)$data["id"];
							else
							{
								// Find the first available product ID.
								for ($pid = 1; $pid <= 1024 && isset($products[$pid % 1024]); $pid++)
								{
								}

								$pid = $pid % 1024;
							}

							try
							{
								if (!$db->NumTransactions())  $db->BeginTransaction();

								if (!isset($products[$pid]))
								{
									$ts = time();

									$db->Query("INSERT", array("products", array(
										"id" => $pid,
										"name" => $data["name"],
										"created" => $ts
									)));

									$products[$pid] = array(
										"name" => $data["name"],
										"created" => $ts,
										"versions" => array()
									);

									ksort($products);
								}
								else
								{
									$db->Query("UPDATE", array("products", array(
										"name" => $data["name"]
									), "WHERE" => "id = ?"), $pid);

									$products[$pid]["name"] = $data["name"];
								}

								$result2 = array(
									"success" => true,
									"id" => $pid,
									"name" => $products[$pid]["name"],
									"created" => $products[$pid]["created"],
									"major_vers" => count($products[$pid]["versions"])
								);
							}
							catch (Exception $e)
							{
								CLI::DisplayError("Unable to set up the product.  " . $e->getMessage(), false, false);

								$result2 = array("success" => false, "error" => "A database exception occurred while setting up the product.", "errorcode" => "db_exception");
							}
						}
					}
					else if ($data["action"] === "get_products")
					{
						// Retrieves the list of known products.
						$result2 = array(
							"success" => true,
							"products" => array()
						);

						foreach ($products as $pid => &$pinfo)
						{
							$result2["products"][$pid] = array(
								"name" => $pinfo["name"],
								"created" => $pinfo["created"],
								"major_vers" => count($pinfo["versions"])
							);
						}

						$result2["products"] = (object)$result2["products"];
					}
					else
					{
						$result2 = array(
							"success" => false,
							"error" => "Unknown 'action'.",
							"errorcode" => "unknown_action"
						);
					}
				}
				else
				{
					$result2 = array(
						"success" => false,
						"error" => "Invalid request.",
						"errorcode" => "invalid_request"
					);
				}

				$client->writedata .= json_encode($result2, JSON_UNESCAPED_SLASHES) . "\n";

				$gs->UpdateClientState($id);
			}
		}

		// Do something with removed clients.
		foreach ($result["removed"] as $id => $result2)
		{
			if (isset($result2["client"]->appdata))
			{
				echo "Client " . $id . " disconnected.\n";

//				echo "Client " . $id . " disconnected.  " . $result2["client"]->recvsize . " bytes received, " . $result2["client"]->sendsize . " bytes sent.  Disconnect reason:\n";
//				echo json_encode($result2["result"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
//				echo "\n";
			}
		}

		// Check the status of the two service file options.
		if ($lastservicecheck <= time() - 3)
		{
			if ($db->NumTransactions())  $db->Commit();

			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename))
			{
				// Reload configuration and then remove reload file.
				echo "Reload config requested.\n";

				@unlink($reloadfilename);
				$running = false;
			}

			$lastservicecheck = time();
		}
	} while ($running);
?>