<?php
	// License server concurrency testing module.  Requires PHP concurrency tester.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	if ($argc < 2)
	{
		echo "Syntax:  test.php StartAtTimestamp\n\n";

		echo "This program is usually run via PHP concurrency tester.  Running tests as a single process, please wait...\n\n";

		$argv[1] = time() - 1;
	}

	// Wait until the start time.
	$diff = ((double)$argv[1] - microtime(true));
	if ($diff > 0)  usleep($diff * 1000000);

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/sdk_license_server.php";
	require_once $rootpath . "/support/random.php";

	$rng = new CSPRNG();

	$lsrv = new LicenseServer();

	// Create a temporary product, major version, and license.
	$result = $lsrv->Connect();
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$result = $lsrv->CreateProduct("Test " . microtime(true));
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$pid = $result["id"];

	$result = $lsrv->SetMajorVersion($pid, 1);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$options = array(
		"expires" => false,
		"date" => time(),
		"product_class" => 0,
		"minor_ver" => 0,
		"custom_bits" => 0,
		"info" => array(
			"quantity" => 1
		)
	);

	$result = $lsrv->CreateLicense($pid, 1, "test@cubiclesoft.com", $options);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$serialnum = $result["serial_num"];

	$numverifyerrors = 0;
	$numverified = 0;
	$numactivated = 0;
	$startts = microtime(true);
	do
	{
		// Simulate new localhost connections by sleeping.
		// Creating real connections rapidly starves the OS of TCP/IP socket handles.
		usleep($rng->GetInt(500, 1800));

		// 1 in 20 chance of performing an activation.
		$activate = ($rng->GetInt(1, 20) == 1);
//$activate = true;
		$result = $lsrv->VerifySerial($serialnum, $pid, 1, "test@cubiclesoft.com", ($activate ? "activate" : false));
		if (!$result["success"])  $numverifyerrors++;
		else
		{
			$numverified++;

			if ($activate)  $numactivated++;
		}

		$diff = microtime(true) - $startts;
	} while ($diff < 10);

	// Remove the product.
	$lsrv->DeleteProduct($pid);

	// Output results.
	if ($argc < 2)  echo "verified_per_sec,activated_per_sec,num_verified,num_activated,verify_errors\n";

	echo ($numverified / $diff) . "," . ($numactivated / $diff) . "," . $numverified . "," . $numactivated . "," . $numverifyerrors . "\n";
?>