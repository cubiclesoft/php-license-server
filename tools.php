<?php
	// PHP-based License Server command-line tools.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/sdk_license_server.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"d" => "debug",
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"debug" => array("arg" => false),
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"allow_opts_after_param" => false
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "The PHP-based License Server command-line tool\n";
		echo "Purpose:  Expose the License Server API to the command-line.  Also verifies correct SDK functionality.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [apigroup api [apioptions]]\n";
		echo "Options:\n";
		echo "\t-d   Enable raw API debug mode.  Dumps the raw data sent and received on the wire.\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " products create -product 1 -name test\n";
		echo "\tphp " . $args["file"] . " -s versions list -product 1\n";

		exit();
	}

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	// Get the API group.
	$apigroups = array(
		"licenses" => "Licenses",
		"license-history" => "License history",
		"versions" => "Major Versions",
		"products" => "Products",
	);

	$apigroup = CLI::GetLimitedUserInputWithArgs($args, false, "API group", false, "Available API groups:", $apigroups, true, $suppressoutput);

	// Get the API.
	switch ($apigroup)
	{
		case "licenses":  $apis = array("search" => "Search licenses", "verify" => "Verify a serial number", "activate" => "Activate a license", "deactivate" => "Deactivate a license", "list-revoked" => "List revoked licenses", "revoke" => "Revoke a license", "restore" => "Restore a revoked license", "create" => "Create a license");  break;
		case "license-history":  $apis = array("search" => "Search license history", "add" => "Add license history log entry");  break;
		case "versions":  $apis = array("list" => "List major versions", "set" => "Set major version information");  break;
		case "products":  $apis = array("list" => "List products", "create" => "Create a product", "delete" => "Delete a product");  break;
	}

	$api = CLI::GetLimitedUserInputWithArgs($args, false, "API", false, "Available APIs:", $apis, true, $suppressoutput);

	$lsrv = new LicenseServer();

	if (isset($args["opts"]["debug"]) && $args["opts"]["debug"])  $lsrv->SetDebug(true);

	$lsrvts = 0;

	function ManageConnection()
	{
		global $lsrv, $lsrvts;

		if ($lsrvts < time() - 15)
		{
			$result = $lsrv->Connect();
			if (!$result["success"])  CLI::DisplayResult($result);

			$lsrvts = time();
		}
	}

	function GetProductID()
	{
		global $suppressoutput, $args, $lsrv;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "product"))  $id = CLI::GetUserInputWithArgs($args, "product", "Product ID", false, "", $suppressoutput);
		else
		{
			ManageConnection();
			$result = $lsrv->GetProducts();
			if (!$result["success"])  CLI::DisplayResult($result);

			$products = array();
			foreach ($result["products"] as $id => $product)
			{
				$products[$id] = $product["name"];
			}
			if (!count($products))  CLI::DisplayError("No products have been created.  Try creating your first product with the API:  products create");
			$id = CLI::GetLimitedUserInputWithArgs($args, "product", "Product ID", false, "Available products:", $products, true, $suppressoutput);
		}

		return $id;
	}

	function GetMajorVersion($pid)
	{
		global $suppressoutput, $args, $lsrv;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "major_ver"))  $ver = CLI::GetUserInputWithArgs($args, "major_ver", "Major version number", false, "", $suppressoutput);
		else
		{
			ManageConnection();
			$result = $lsrv->GetMajorVersions($pid);
			if (!$result["success"])  CLI::DisplayResult($result);

			$versions = array();
			foreach ($result["versions"] as $ver => $vinfo)
			{
				$versions[$ver] = "Version " . $ver . (isset($vinfo["info"]["product_classes"]) && count($vinfo["info"]["product_classes"]) ? " (" . implode(", ", $vinfo["info"]["product_classes"]) . ")" : "") . ", " . ($vinfo["active"] ? "Active" : "Deactivated") . ", " . date("Y-m-d", $vinfo["created"]);
			}
			if (!count($versions))  CLI::DisplayError("No major versions have been setup.  Try setting up your first major version with the API:  versions set");
			$ver = CLI::GetLimitedUserInputWithArgs($args, "major_ver", "Major version number", false, "Available major version numbers:", $versions, true, $suppressoutput);
		}

		return $ver;
	}

	if ($apigroup === "licenses")
	{
		// Licenses.
		if ($api === "search")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"mode" => array("arg" => true, "multiple" => true),
					"serial_num" => array("arg" => true, "multiple" => true),
					"userinfo" => array("arg" => true, "multiple" => true),
					"order_num" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$modes = array(
				"serial" => "Serial number",
				"userinfo" => "User information",
				"order" => "Order number"
			);

			$mode = CLI::GetLimitedUserInputWithArgs($args, "mode", "Mode", false, "Available search modes:", $modes, true, $suppressoutput);

			if ($mode === "serial")
			{
				$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Exact serial number", false, "", $suppressoutput);

				ManageConnection();
				CLI::DisplayResult($lsrv->GetLicenses($serialnum));
			}
			else if ($mode === "userinfo")
			{
				$userinfo = CLI::GetUserInputWithArgs($args, "userinfo", "Exact user information", false, "The next question asks for user-specific information.  This is a string that can contain things like a user ID and/or an email address.", $suppressoutput);

				ManageConnection();
				CLI::DisplayResult($lsrv->GetLicenses(false, $userinfo));
			}
			else if ($mode === "order")
			{
				do
				{
					$valid = false;

					$ordernum = CLI::GetUserInputWithArgs($args, "order_num", "Exact order number", false, "", $suppressoutput);

					$result = $lsrv->ExtractOrderNumberInfo($ordernum);
					if ($result === false)  CLI::DisplayError("The order number is not valid.", false, false);
					else  $valid = true;
				} while (!$valid);

				ManageConnection();
				CLI::DisplayResult($lsrv->GetLicenses(false, false, -1, -1, array("created" => $result["created"], "order_num" => $result["order_num"])));
			}
		}
		else if ($api === "verify" || $api === "activate" || $api === "deactivate" || $api === "increment-downloads")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"serial_num" => array("arg" => true, "multiple" => true),
					"product" => array("arg" => true, "multiple" => true),
					"major_ver" => array("arg" => true, "multiple" => true),
					"userinfo" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Serial number", false, "", $suppressoutput);
			$pid = GetProductID();
			$majorver = GetMajorVersion($pid);
			$userinfo = CLI::GetUserInputWithArgs($args, "userinfo", "User information", false, "The next question asks for user-specific information.  This is the string that is associated with the license.", $suppressoutput);

			if ($api === "activate")  $mode = "activate";
			else if ($api === "deactivate")  $mode = "deactivate";
			else if ($api === "increment-downloads")  $mode = "download";
			else  $mode = false;

			ManageConnection();
			CLI::DisplayResult($lsrv->VerifySerial($serialnum, $pid, $majorver, $userinfo, $mode));
		}
		else if ($api === "list-revoked")
		{
			ManageConnection();
			CLI::DisplayResult($lsrv->GetRevokedLicenses());
		}
		else if ($api === "revoke")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"serial_num" => array("arg" => true, "multiple" => true),
					"reason" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			do
			{
				$valid = false;
				$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Exact serial number", false, "", $suppressoutput);

				ManageConnection();
				$result = $lsrv->GetLicenses($serialnum);
				if (!$result["success"])  CLI::DisplayResult($result);
				if (!count($result["licenses"]))  CLI::DisplayError("The serial number is not valid.", false, false);
				else if (count($result["licenses"]) > 1)  CLI::DisplayError("The serial number is valid but appears for multiple products.  Operation not supported via this tool at this time.", false, false);
				else  $valid = true;
			} while (!$valid);

			$license = $result["licenses"][0];

			$reason = CLI::GetUserInputWithArgs($args, "reason", "Revoke reason", false, "", $suppressoutput);

			ManageConnection();
			CLI::DisplayResult($lsrv->RevokeLicense($license["serial_num"], $license["product_id"], $license["major_ver"], $license["userinfo"], $reason));
		}
		else if ($api === "restore")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"serial_num" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			do
			{
				$valid = false;
				$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Exact serial number", false, "", $suppressoutput);

				ManageConnection();
				$result = $lsrv->GetLicenses($serialnum);
				if (!$result["success"])  CLI::DisplayResult($result);
				if (!count($result["licenses"]))  CLI::DisplayError("The serial number is not valid.", false, false);
				else if (count($result["licenses"]) > 1)  CLI::DisplayError("The serial number is valid but appears for multiple products.  Operation not supported via this tool at this time.", false, false);
				else  $valid = true;
			} while (!$valid);

			$license = $result["licenses"][0];

			ManageConnection();
			CLI::DisplayResult($lsrv->RestoreLicense($license["serial_num"], $license["product_id"], $license["major_ver"], $license["userinfo"]));
		}
		else if ($api === "create")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"product" => array("arg" => true, "multiple" => true),
					"major_ver" => array("arg" => true, "multiple" => true),
					"minor_ver" => array("arg" => true, "multiple" => true),
					"userinfo" => array("arg" => true, "multiple" => true),
					"expires" => array("arg" => true),
					"date" => array("arg" => true, "multiple" => true),
					"product_class" => array("arg" => true, "multiple" => true),
					"custom_bits" => array("arg" => true, "multiple" => true),
					"quantity" => array("arg" => true),
					"max_activations" => array("arg" => true),
					"max_downloads" => array("arg" => true),
					"extra" => array("arg" => true),
					"prefix" => array("arg" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$pid = GetProductID();
			$majorver = GetMajorVersion($pid);

			ManageConnection();
			$result = $lsrv->GetMajorVersions($pid);
			if (!$result["success"])  CLI::DisplayResult($result);
			$vinfo = $result["versions"][$majorver];

			do
			{
				$valid = false;
				$minorver = (int)CLI::GetUserInputWithArgs($args, "minor_ver", "Minor version number", (isset($vinfo["info"]["minor_ver"]) ? $vinfo["info"]["minor_ver"] : "0"), "", $suppressoutput);
				if ($minorver < 0 || $minorver > 255)  CLI::DisplayError("The minor version number must be an integer between 0 and 255 inclusive.", false, false);
				else  $valid = true;
			} while (!$valid);

			$userinfo = CLI::GetUserInputWithArgs($args, "userinfo", "User information", false, "The next question asks for user-specific information.  This is a string that can contain things like an email address and/or a user ID.", $suppressoutput);
			$expires = CLI::GetYesNoUserInputWithArgs($args, "expires", "License expires", "N", "", $suppressoutput);

			do
			{
				$valid = false;
				if ($expires)  $date = CLI::GetUserInputWithArgs($args, "date", "Expiration date", date("Y-m-d", mktime(0, 0, 0, date("n"), date("j") + 31)), "", $suppressoutput);
				else  $date = CLI::GetUserInputWithArgs($args, "date", "Creation date", date("Y-m-d"), "", $suppressoutput);

				$ts = strtotime($date);
				if ($ts < 0 || $ts === false)  CLI::DisplayError("Unable to parse the date.  Try again.", false, false);
				else  $valid = true;
			} while (!$valid);

			if (isset($vinfo["info"]["product_classes"]) && count($vinfo["info"]["product_classes"]))
			{
				$productclass = (int)CLI::GetLimitedUserInputWithArgs($args, "product_class", "Product class", false, "Available product classes:", $vinfo["info"]["product_classes"], true, $suppressoutput);
			}
			else
			{
				do
				{
					$valid = false;
					$productclass = (int)CLI::GetUserInputWithArgs($args, "product_class", "Product class", false, "", $suppressoutput);
					if ($productclass < 0 || $productclass > 15)  CLI::DisplayError("The product class must be an integer between 0 and 15 inclusive.", false, false);
					else  $valid = true;
				} while (!$valid);
			}

			do
			{
				$valid = false;
				$custombits = (int)CLI::GetUserInputWithArgs($args, "custom_bits", "Custom bits", "0", "", $suppressoutput);
				if ($custombits < 0 || $custombits > 31)  CLI::DisplayError("The custom bits must be an integer between 0 and 31 inclusive.", false, false);
				else  $valid = true;
			} while (!$valid);

			$info = array();
			$info["quantity"] = (int)CLI::GetUserInputWithArgs($args, "quantity", "Quantity", "1", "", $suppressoutput);
			if ($info["quantity"] < 1)  $info["quantity"] = 1;

			$maxactive = (int)CLI::GetUserInputWithArgs($args, "max_activations", "Maximum activations", "-1", "The next question deals with the maximum number of license activations.  Use -1 for the major version limit.", $suppressoutput);
			if ($maxactive > -1)  $info["max_activations"] = $maxactive;

			$maxdownload = (int)CLI::GetUserInputWithArgs($args, "max_downloads", "Maximum downloads", "-1", "The next question deals with the maximum number of allowed downloads of the software.  Use -1 for the major version limit.", $suppressoutput);
			if ($maxdownload > -1)  $info["max_downloads"] = $maxdownload;

			$info["extra"] = CLI::GetUserInputWithArgs($args, "extra", "Extra information", "", "The next question asks for extra information.  This is an optional string that can contain things like organization name, billing address, or an invoice/order number/transaction ID to be associated with the license.", $suppressoutput);

			$prefix = CLI::GetUserInputWithArgs($args, "prefix", "Order number prefix", "", "The next question asks for a string prefix for an online order.  Use a period to not generate an order number.", $suppressoutput);

			$options = array(
				"expires" => $expires,
				"date" => $ts,
				"product_class" => $productclass,
				"minor_ver" => $minorver,
				"custom_bits" => $custombits,
				"info" => $info
			);

			if ($prefix === ".")  $options["order_num"] = false;

			ManageConnection();
			$result = $lsrv->CreateLicense($pid, $majorver, $userinfo, $options);

			if ($result["success"] && $prefix !== ".")  $result["user_order_num"] = $lsrv->GetUserOrderNumber($prefix, $result["created"], $result["order_num"]);

			CLI::DisplayResult($result);
		}
	}
	else if ($apigroup === "license-history")
	{
		// License history.
		if ($api === "search")
		{
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"mode" => array("arg" => true, "multiple" => true),
					"log_id" => array("arg" => true, "multiple" => true),
					"serial_num" => array("arg" => true, "multiple" => true),
					"userinfo" => array("arg" => true, "multiple" => true),
					"log_type" => array("arg" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$modes = array(
				"id" => "Log ID",
				"serial" => "Serial number",
				"userinfo" => "User information"
			);

			$mode = CLI::GetLimitedUserInputWithArgs($args, "mode", "Mode", false, "Available search modes:", $modes, true, $suppressoutput);

			if ($mode === "id")
			{
				$logid = CLI::GetUserInputWithArgs($args, "log_id", "Log ID", false, "", $suppressoutput);

				ManageConnection();
				CLI::DisplayResult($lsrv->GetHistoryByID($logid));
			}

			$serialnum = false;
			$userinfo = false;

			if ($mode === "serial")
			{
				$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Exact serial number", false, "", $suppressoutput);
			}
			else if ($mode === "userinfo")
			{
				$userinfo = CLI::GetUserInputWithArgs($args, "userinfo", "Exact user information", false, "The next question asks for user-specific information.  This is a string that can contain things like a user ID and/or an email address.", $suppressoutput);
			}

			$logtype = CLI::GetUserInputWithArgs($args, "log_type", "Log type", "", "The next question asks for the type of log to retrieve.  Leave blank for all types.", $suppressoutput);

			ManageConnection();
			CLI::DisplayResult($lsrv->GetHistory($serialnum, $userinfo, -1, -1, ($logtype !== "" ? $logtype : false)));
		}
		else if ($api === "add")
		{
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"serial_num" => array("arg" => true, "multiple" => true),
					"product" => array("arg" => true, "multiple" => true),
					"major_ver" => array("arg" => true, "multiple" => true),
					"userinfo" => array("arg" => true, "multiple" => true),
					"log_type" => array("arg" => true, "multiple" => true),
					"log" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$serialnum = CLI::GetUserInputWithArgs($args, "serial_num", "Serial number", false, "", $suppressoutput);
			$pid = GetProductID();
			$majorver = GetMajorVersion($pid);
			$userinfo = CLI::GetUserInputWithArgs($args, "userinfo", "User information", false, "The next question asks for user-specific information.  This is the string that is associated with the license.", $suppressoutput);

			$logtype = CLI::GetUserInputWithArgs($args, "log_type", "Log type", false, "The next question asks for the type of log to create.", $suppressoutput);
			$log = CLI::GetUserInputWithArgs($args, "log", "Log", false, "The next question asks for the data to write to the log.", $suppressoutput);

			ManageConnection();
			CLI::DisplayResult($lsrv->AddHistory($serialnum, $pid, $majorver, $userinfo, $logtype, $log));
		}
	}
	else if ($apigroup === "versions")
	{
		// Major versions.
		if ($api === "list")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"product" => array("arg" => true, "multiple" => true),
					"secrets" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$pid = GetProductID();
			$secrets = CLI::GetYesNoUserInputWithArgs($args, "secrets", "Include encryption secrets", "N", "Unless there is a need to see the serial number encryption secrets, leave the default option set as-is to not include them.", $suppressoutput);

			ManageConnection();
			CLI::DisplayResult($lsrv->GetMajorVersions($pid, false, $secrets));
		}
		else if ($api === "set")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"product" => array("arg" => true, "multiple" => true),
					"major_ver" => array("arg" => true, "multiple" => true),
					"minor_ver" => array("arg" => true, "multiple" => true),
					"patch_ver" => array("arg" => true, "multiple" => true),
					"active" => array("arg" => true, "multiple" => true),
					"max_activations" => array("arg" => true, "multiple" => true),
					"max_downloads" => array("arg" => true, "multiple" => true),
					"encode_chars" => array("arg" => true, "multiple" => true),
					"product_classes" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$pid = GetProductID();

			do
			{
				$valid = false;
				$ver = (int)CLI::GetUserInputWithArgs($args, "major_ver", "Major version number", false, "", $suppressoutput);
				if ($ver < 0 || $ver > 255)  CLI::DisplayError("The major version number must be an integer between 0 and 255 inclusive.", false, false);
				else  $valid = true;
			} while (!$valid);

			// Load versions and locate existing version info.
			ManageConnection();
			$result = $lsrv->GetMajorVersions($pid);
			if (!$result["success"])  CLI::DisplayResult($result);

			$info = (isset($result["versions"][$ver]) ? $result["versions"][$ver]["info"] : array());

			$info["minor_ver"] = (int)CLI::GetUserInputWithArgs($args, "minor_ver", "Latest minor version number", (isset($info["minor_ver"]) ? $info["minor_ver"] : "0"), "", $suppressoutput);
			if ($info["minor_ver"] < 0)  $info["minor_ver"] = 0;
			if ($info["minor_ver"] > 255)  CLI::DisplayError("[Warning] The minor version number should be an integer between 0 and 255 inclusive.", false, false);

			$info["patch_ver"] = (int)CLI::GetUserInputWithArgs($args, "patch_ver", "Latest patch/build version number", (isset($info["patch_ver"]) ? $info["patch_ver"] : "0"), "", $suppressoutput);
			if ($info["patch_ver"] < 0)  $info["patch_ver"] = 0;

			$active = CLI::GetYesNoUserInputWithArgs($args, "active", "Active", (!isset($result["versions"][$ver]) || $result["versions"][$ver]["active"] ? "Y" : "N"), "Deactivating a major version causes all serial number verifications and license creations against the license server for that version to fail.", $suppressoutput);
			$maxactive = (int)CLI::GetUserInputWithArgs($args, "max_activations", "Default maximum activations", (isset($info["max_activations"]) ? $info["max_activations"] : "-1"), "The next question deals with the default maximum number of license activations.  Use -1 for unlimited.", $suppressoutput);
			$maxdownload = (int)CLI::GetUserInputWithArgs($args, "max_downloads", "Maximum downloads", (isset($info["max_downloads"]) ? $info["max_downloads"] : "-1"), "The next question deals with the default maximum number of allowed downloads of the software.  Use -1 for unlimited.  Use 0 to disable downloads (e.g. creating a new major version).", $suppressoutput);
			$encodechars = CLI::GetUserInputWithArgs($args, "encode_chars", "Encoding characters", (isset($info["encode_chars"]) ? $info["encode_chars"] : "-"), "The next question asks for a 32 byte character string to use for encoding serial numbers.  Use a single hyphen to select the default character set.  This is unable to change after the server starts issuing licenses for each major version.", $suppressoutput);

			if ($maxactive > -1)  $info["max_activations"] = $maxactive;
			else  unset($info["max_activations"]);

			if ($maxdownload > -1)  $info["max_downloads"] = $maxdownload;
			else  unset($info["max_downloads"]);

			if (strlen($encodechars) == 32)  $info["encode_chars"] = $encodechars;
			else  unset($info["encode_chars"]);

			do
			{
				$valid = false;
				$productclasses = CLI::GetUserInputWithArgs($args, "product_classes", "Product classes JSON", (isset($info["product_classes"]) ? json_encode((object)$info["product_classes"], JSON_UNESCAPED_SLASHES) : "{}"), "The next question asks for a JSON object mapping product classes to names (e.g. {\"0\": \"Standard\", \"1\": \"Pro\", \"2\": \"Enterprise\"}).", $suppressoutput);
				$data = json_decode($productclasses, true);
				if (!is_array($data))  CLI::DisplayError("The supplied JSON object is invalid.", false, false);
				else
				{
					$info["product_classes"] = (object)$data;

					$valid = true;
				}
			} while (!$valid);

			ManageConnection();
			CLI::DisplayResult($lsrv->SetMajorVersion($pid, $ver, $active, $info));
		}
	}
	else if ($apigroup === "products")
	{
		// Products.
		if ($api === "list")
		{
			ManageConnection();
			CLI::DisplayResult($lsrv->GetProducts());
		}
		else if ($api === "create")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"id" => array("arg" => true, "multiple" => true),
					"name" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$id = CLI::GetUserInputWithArgs($args, "id", "Product ID", "", "", $suppressoutput);
			$name = CLI::GetUserInputWithArgs($args, "name", "Product name", false, "", $suppressoutput);

			if ($id === "")  $id = -1;

			ManageConnection();
			CLI::DisplayResult($lsrv->CreateProduct($name, (int)$id));
		}
		else if ($api === "delete")
		{
			// Process the parameters.
			$options = array(
				"shortmap" => array(
					"?" => "help"
				),
				"rules" => array(
					"product" => array("arg" => true, "multiple" => true),
					"delete_confirm" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, array_merge(array(""), $args["params"]));

			if (isset($args["opts"]["help"]))  CLI::DisplayResult(array("success" => true, "options" => array_keys($options["rules"])));

			$id = GetProductID();

			$sure = CLI::GetYesNoUserInputWithArgs($args, "delete_confirm", "Delete product", "N", "Are you really sure you want to delete this product, all of its versions, and all of its licenses?  This action cannot be undone.");

			if (!$sure)  CLI::LogMessage("[Notice] Nothing was done.  Whew!  That was a close one!");
			else
			{
				ManageConnection();
				CLI::DisplayResult($lsrv->DeleteProduct($id));
			}
		}
	}
?>