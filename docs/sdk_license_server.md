LicenseServer Class:  'support/sdk_license_server.php'
======================================================

A self-contained client SDK in PHP for communicating with the PHP-based License Server.

Example usage:

```php
<?php
	require_once "support/sdk_license_server.php";

	$productid = 1;

	$lsrv = new LicenseServer();
	$result = $lsrv->Connect();
	if (!$result["success"])
	{
		echo "Error connecting to license server.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	// Retrieve non-revoked licenses for a specific user for a specific product.
	$result = $lsrv->GetLicenses(false, "someuser@domain.com", $productid, -1, array("revoked" => false));
var_dump($result);
?>
```

LicenseServer::SetDebug($debug)
-------------------------------

Access:  public

Parameters:

* $debug - A boolean indicating whether or not to enable debugging output.

Returns:  Nothing.

This function turns debugging mode on and off.  The initial default is off.  When debugging mode is turned on, the class may display sensitive information.

LicenseServer::Connect($host = "127.0.0.1", $port = 24276)
----------------------------------------------------------

Access:  public

Parameters:

* $host - A string containing an IP address or domain (Default is "127.0.0.1").
* $port - An integer containing a port number (Default is 24276).

Returns:  A standard array of information.

This function connects to a license server instance.

LicenseServer::VerifySerial($serialnum, $productid, $majorver, $userinfo, $mode = false, $log = false)
------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A string containing a serial number to verify.
* $productid - An integer containing a valid product ID.
* $majorver - An integer containing a major version number for the product.
* $userinfo - A string containing user information associated with the serial number + product ID + major version.
* $mode - A boolean of false or a string containing one of 'activate', 'deactivate', or 'download' (Default is false).
* $log - A boolean of false or a string containing information to add to the history log for the license when $mode is not false (Default is false).

Returns:  A standard array of information.

This function verifies a serial number against the license server.  The database is only queried when $mode is specified.  The serial number itself is protected with 16 rounds of feistel network-style encryption that depend on secret keys known only to the license server when using Internet verification.

Note:  The license server itself is not meant to be Internet-facing and, as such, starts as a localhost-only server.  Internet communication with the license server should be done via an intermediate REST API.

LicenseServer::RevokeLicense($serialnum, $productid, $majorver, $userinfo, $reason, $log = false)
-------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A string containing a serial number to verify.
* $productid - An integer containing a valid product ID.
* $majorver - An integer containing a major version number for the product.
* $userinfo - A string containing user information associated with the serial number + product ID + major version.
* $reason - A string containing the reason for the revocation.
* $log - A boolean of false or a string containing information to add to the history log for the license (Default is false).

Returns:  A standard array of information.

This function revokes the specified license in the license server.

Note:  Revoking a license should be very rare due to increased RAM usage (e.g. piracy).  This is necessary to be able to handle high performance responses to `VerifySerial()` requests.  An alternative to revoking a license is to set both `max_activations` and `max_downloads` to 0, which can be used to prevent users from activating and downloading the software.

LicenseServer::RestoreLicense($serialnum, $productid, $majorver, $userinfo, $log = false)
-----------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A string containing a serial number to verify.
* $productid - An integer containing a valid product ID.
* $majorver - An integer containing a major version number for the product.
* $userinfo - A string containing user information associated with the serial number + product ID + major version.
* $log - A boolean of false or a string containing information to add to the history log for the license (Default is false).

Returns:  A standard array of information.

This function restores the previously revoked license in the license server.

LicenseServer::CreateLicense($productid, $majorver, $userinfo, $options = array())
----------------------------------------------------------------------------------

Access:  public

Parameters:

* $productid - An integer containing a valid product ID.
* $majorver - An integer containing a major version number for the product.
* $userinfo - A string containing user information associated with the serial number + product ID + major version.
* $options - An array of options containing key-value pairs to pass to the license server (Default is array()).

Returns:  A standard array of information.

This function creates a new license and can update the information object of existing licenses.

The $options array accepts these options:

* info - An array containing reserved server options (`max_activations`, `max_downloads`, `activations`, `downloads`, `password`) and custom data (Default is array()).
* expires - A boolean indicating whether or not the serial number expires (Default is false).  This option affects how the `date` option is interpreted.
* date - An integer containing a UNIX timestamp representing the date of issuance OR the date of expiration (Default is the current time).
* product_class - An integer between 0 and 15 inclusive for identifying a product classification such as Standard vs. Pro vs. Enterprise (Default is 0).
* minor_ver - An integer between 0 and 255 inclusive for identifying the minor version number of the product that the license is for (Default is 0).
* custom_bits - An integer between 0 and 31 inclusive for custom bits of application-specific data to include in the serial number (Default is 0).
* log - A string containing information to add to the history log for the license.

Example:

```php
<?php
	require_once "support/sdk_license_server.php";

	$productid = 1;
	$majorver = 1;

	$lsrv = new LicenseServer();
	$result = $lsrv->Connect();
	if (!$result["success"])
	{
		echo "Error connecting to license server.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	// Retrieve the most recent downloadable version.
	$result = $lsrv->GetMajorVersions($productid, true);
	if (!$result["success"])
	{
		echo "The license server returned an unexpected error while retrieving version information.  Try again later.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	$productname = $result["product_name"];
	$versions = $result["versions"];
	if (!count($versions))
	{
		echo "No versions of " . htmlspecialchars($productname) . " are available at the moment.\n";

		exit();
	}

	$majorver = max(array_keys($versions));
	$minorver = $versions[$majorver]["info"]["minor_ver"];

	$displaynamever = $productname . " " . $majorver . "." . $minorver;

	// Create a license that expires after a year.
	$expirets = mktime(0, 0, 0, date("n") + 366);

	$options = array(
		"expires" => true,
		"date" => $expirets,
		"product_class" => 0,
		"minor_ver" => $minorver,
		"custom_bits" => 0,
		"info" => array(
//			"max_activations" => 10,
//			"max_downloads" => 25,
			"quantity" => 1,
			"extra" => "Some User"
		),
		"log" => json_encode($log, JSON_UNESCAPED_SLASHES)
	);

	$result = $lsrv->CreateLicense($productid, $majorver, "someuser@domain.com", $options);
	if (!$result["success"])
	{
		echo "The license server failed to issue the license.  Try again later.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	echo "Order #" . $lsrv->GetUserOrderNumber("INCLS", $result["created"], $result["order_num"])) . "\n";
?>
```

LicenseServer::GetUserOrderNumber($prefix, $created, $ordernum)
---------------------------------------------------------------

Access:  public static

Parameters:

* $prefix - A string prefix.
* $created - An integer containing a UNIX timestamp representing the creation date of the license.
* $ordernum - An integer containing the order number of the license.

Returns:  A standard array of information.

This static function returns a user-displayable, anonymized order number that can be used to look up the license later.

The license server resets the set of possible order numbers every 10 minutes and randomly selects an order number for each license in the currently valid range, which expands only as necessary.  Order numbers are calculated in such a way to:  Prevent the determination of how many licenses have been sold, reduce the ability to guess valid order numbers when contacting customer service, and keep the average user from perceiving any normality to the sequencing.

LicenseServer::ExtractOrderNumberInfo($userordernum)
----------------------------------------------------

Access:  public static

Parameters:

* $userordernum - A string containing an order number previously generated by `GetUserOrderNumber()`.

Returns:  An array of information on success, a boolean of false otherwise.

This static function extracts the components of an order number and returns them as an array.  The information returned is intended to be passed onto a `GetLicenses()` call.

Example:

```php
<?php
	require_once "support/sdk_license_server.php";

	$productid = 1;
	$majorver = 1;

	$lsrv = new LicenseServer();
	$result = $lsrv->Connect();
	if (!$result["success"])
	{
		echo "Error connecting to license server.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	$orderinfo = $lsrv->ExtractOrderNumberInfo("INCLS123456-1234");
	if ($orderinfo === false)
	{
		echo "Invalid order number.\n";

		exit();
	}

	$result = $lsrv->GetLicenses(false, "someuser@domain.com", -1, -1, array("revoked" => false, "created" => $orderinfo["created"], "order_num" => $orderinfo["order_num"]));
	if (!$result["success"])
	{
		echo "An error occurred while loading the order information.  " . $result["error"] . " (" . $result["errorcode"] . ")\n";

		exit();
	}

	if (count($result["licenses"]) != 1)
	{
		echo "Order information not found.\n";

		exit();
	}

	$license = $result["licenses"][0];
var_dump($license);
?>
```

LicenseServer::GetRevokedLicenses($productid = -1, $majorver = -1)
------------------------------------------------------------------

Access:  public

Parameters:

* $productid - An integer containing a valid product ID (Default is -1).
* $majorver - An integer containing a major version number for the product (Default is -1).

Returns:  A standard array of information.

This function returns the currently revoked licenses in the system.  The licenses returned can be limited to a specific product and major version.

LicenseServer::GetLicenses($serialnum = false, $userinfo = false, $productid = -1, $majorver = -1, $options = array())
----------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A boolean of false or a string containing a serial number (Default is false).
* $userinfo - A boolean of false or a string containing user information (Default is false).
* $productid - An integer containing a valid product ID (Default is -1).
* $majorver - An integer containing a major version number for the product (Default is -1).
* $options - An array of additional search options (Default is array()).

Returns:  A standard array of information.

This function returns licenses associated with the requested license search options.

When $userinfo is false, up to 25 matching licenses are returned.  When $userinfo is a string, up to 300 matching licenses are returned.

The $options array accepts these options:

* userinfo_like - A boolean indicating that $userinfo should be a prefix string as part of a LIKE query instead of exact match (Default is false).
* created - An integer containing the estimated creation time of an order number as returned by `ExtractOrderNumberInfo()`.  Also requires the 'order_num' option.
* order_num - An integer containing the order number as returned by `ExtractOrderNumberInfo()`.  Also requires the 'created' option.
* password - A string containing a user-submitted password (e.g. to verify access to a product support center or to download a file).
* revoked - A boolean that specifies whether or not to return revoked licenses (Default is true).

LicenseServer::AddHistory($serialnum, $productid, $majorver, $userinfo, $type, $log)
------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A string containing a serial number.
* $productid - An integer containing a valid product ID.
* $majorver - An integer containing a major version number for the product.
* $userinfo - A string containing user information associated with the serial number + product ID + major version.
* $type - A string containing the log type (e.g. "customer_call").
* $log - A string containing information to add to the history log for the license.

Returns:  A standard array of information.

This function adds a new custom log entry to the history log for a license.

LicenseServer::GetHistoryByID($id)
----------------------------------

Access:  public

Parameters:

* $id - A string containing a valid log ID.

Returns:  A standard array of information.

This function returns the history log referenced by the specified log ID.

LicenseServer::GetHistory($serialnum = false, $userinfo = false, $productid = -1, $majorver = -1, $type = false)
----------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $serialnum - A boolean of false or a string containing a serial number (Default is false).
* $productid - An integer containing a valid product ID (Default is -1).
* $majorver - An integer containing a major version number for the product (Default is -1).
* $userinfo - A boolean of false or a string containing user information (Default is false).
* $type - A boolean of false or a string containing the log type to return (e.g. "created") (Default is false).

Returns:  A standard array of information.

This function returns up to 100 of the most recent history logs for matching licenses.

LicenseServer::SetMajorVersion($productid, $majorver, $active = true, $info = array())
--------------------------------------------------------------------------------------

Access:  public

Parameters:

* $productid - An integer containing a valid product ID.
* $majorver - An integer between 0 and 255 inclusive for identifying the major version number of the product that the license is for.
* $active - A boolean indicating whether or not this major version should be active (affects all licenses).
* $info - An array containing reserved server options (Default is array()).

Returns:  A standard array of information.

This function sets major version information for a product.  Deactivating a major version causes all serial number verifications and license creations against the license server for that version to fail.

The $info array accepts these options:

* minor_ver - An integer between 0 and 255 inclusive for identifying the minor version number of the product that the license is for (Default is 0).
* patch_ver - An integer containing a patch/build version number.
* max_activations - An integer containing the default maximum number of license activations for the major version (Default is -1).
* max_downloads - An integer containing the default maximum number of downloads for the major version (Default is -1).
* encode_chars - A string containing exactly 32 characters to use to encode the serial number (Default is 'abcdefghijkmnpqrstuvwxyz23456789').
* encode_chars - A string containing exactly 32 byte characters to use for encoding serial numbers.  This is unable to change after the license server starts issuing licenses for each major version (Default is 'abcdefghijkmnpqrstuvwxyz23456789').
* product_classes - An array mapping integers between 0 through 15 inclusive for mapping a product classification to human-readable strings such as 'Standard', 'Pro', and 'Enterprise' (Default is array()).

LicenseServer::GetMajorVersions($productid, $downloadableonly = false, $secrets = false)
----------------------------------------------------------------------------------------

Access:  public

Parameters:

* $productid - An integer containing a valid product ID.
* $downloadableonly - A boolean indicating that major versions with `max_downloads` != 0 should be returned (Default is false).
* $secrets - A boolean indicating whether or not encryption and validation secrets should be returned (Default is false).

Returns:  A standard array of information.

This function returns the major versions for a product.  There is generally little need to include the encryption/validation secrets except to make an offline version of a software product.

LicenseServer::DeleteProduct($productid)
----------------------------------------

Access:  public

Parameters:

* $productid - An integer containing a valid product ID.

Returns:  A standard array of information.

This function deletes a product, all major versions, and all associated licenses.  This operation cannot be undone.

Can be useful for removing a test/preview product.  Other than that, this is a pretty dangerous operation.

LicenseServer::CreateProduct($name, $productid = -1)
----------------------------------------------------

Access:  public

Parameters:

* $name - A string containing a product name.
* $productid - An integer between 0 and 1023 inclusive or -1 to automatically select the next available product ID (Default is -1).

Returns:  A standard array of information.

This function creates a product or changes an existing product's name.

LicenseServer::GetProducts()
----------------------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function returns the list of products that have been registered with the license server.

LicenseServer::RunAPI($data)
----------------------------

Access:  protected

Parameters:

* $data - An array containing the API request to encode and send.

Returns:  A standard array of information.

This internal function sends the request to and processes the response from the license server and returns it.  If debugging mode is enabled, all network communications are displayed.

LicenseServer::LSTranslate($format, ...)
----------------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.
