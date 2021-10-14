<?php
	// Serial number generator/validator.
	// (C) 2020 CubicleSoft.  All Rights Reserved.

	class SerialNumber
	{
		// Generates a 16 character alphanumeric serial number with encrypted product info.
		public static function Generate($productid, $majorver, $userinfo, $options)
		{
			if (!isset($options["expires"]) || !is_bool($options["expires"]))  $options["expires"] = false;
			if (!isset($options["date"]) || !is_int($options["date"]))  $options["date"] = time();
			if (!isset($options["product_class"]))  $options["product_class"] = 0;
			if (!isset($options["minor_ver"]))  $options["minor_ver"] = 0;
			if (!isset($options["custom_bits"]))  $options["custom_bits"] = 0;
			if (!isset($options["encode_chars"]) || !is_string($options["encode_chars"]) || strlen($options["encode_chars"]) < 32)  $options["encode_chars"] = "abcdefghijkmnpqrstuvwxyz23456789";

			$options["expires"] = (bool)$options["expires"];
			$options["date"] = (int)$options["date"];
			$options["product_class"] = (int)$options["product_class"];
			$majorver = (int)$majorver;
			$options["minor_ver"] = (int)$options["minor_ver"];
			$options["custom_bits"] = (int)$options["custom_bits"];

			if ($productid < 0 || $productid > 1023)  return array("success" => false, "error" => self::SNTranslate("Invalid product ID.  Outside valid range."), "errorcode" => "invalid_product_id");
			if ($majorver < 0 || $majorver > 255)  return array("success" => false, "error" => self::SNTranslate("Invalid major version.  Outside valid range."), "errorcode" => "invalid_major_ver");
			if ($options["date"] < 0 || $options["date"] / 86400 / 365 > 2872)  return array("success" => false, "error" => self::SNTranslate("Invalid date.  Outside valid range."), "errorcode" => "invalid_date");
			if ($options["product_class"] < 0 || $options["product_class"] > 15)  return array("success" => false, "error" => self::SNTranslate("Invalid product classification.  Outside valid range."), "errorcode" => "invalid_product_class");
			if ($options["minor_ver"] < 0 || $options["minor_ver"] > 255)  return array("success" => false, "error" => self::SNTranslate("Invalid minor version.  Outside valid range."), "errorcode" => "invalid_minor_ver");
			if ($options["custom_bits"] < 0 || $options["custom_bits"] > 31)  return array("success" => false, "error" => self::SNTranslate("Invalid custom bits.  Outside valid range."), "errorcode" => "invalid_custom_bits");

			if (!isset($options["encrypt_secret"]))  return array("success" => false, "error" => self::SNTranslate("Missing encryption HMAC secret."), "errorcode" => "missing_encrypt_secret");
			if (!is_string($options["encrypt_secret"]))  return array("success" => false, "error" => self::SNTranslate("Invalid encryption HMAC secret."), "errorcode" => "invalid_encrypt_secret");
			if (strlen($options["encrypt_secret"]) < 20)  return array("success" => false, "error" => self::SNTranslate("Encryption HMAC secret must be at least 20 characters."), "errorcode" => "encrypt_secret_too_short");

			if (!isset($options["validate_secret"]))  return array("success" => false, "error" => self::SNTranslate("Missing validation HMAC secret."), "errorcode" => "missing_validate_secret");
			if (!is_string($options["validate_secret"]))  return array("success" => false, "error" => self::SNTranslate("Invalid validation HMAC secret."), "errorcode" => "invalid_validate_secret");
			if (strlen($options["validate_secret"]) < 20)  return array("success" => false, "error" => self::SNTranslate("Validation HMAC secret must be at least 20 characters."), "errorcode" => "validate_secret_too_short");

			if (is_array($userinfo))  $userinfo = implode("|", $userinfo);
			else  $userinfo = (string)$userinfo;

			// Format of each key (big-endian, 10 bytes):
			//
			//  1 bit:   Expires - affects Date field (0 = No, 1 = Yes)
			// 20 bits:  Date issued or expires ((int)(UNIX timestamp / 86400), ~2873 years)
			// 10 bits:  Product ID (0-1023)
			//  4 bits:  Product classification (e.g. 0 = Standard, 1 = Pro, 2 = Enterprise)
			//  8 bits:  Major version (0-255)
			//  8 bits:  Minor version (0-255)
			//  5 bits:  Custom bits per app (e.g. flags)
			// 24 bits:  Start of HMAC SHA-1 of the first 56 bits (7 bytes) of this serial '|' user-specific info (e.g. email).

			$date = (int)($options["date"] / 86400);

			$serial = array();
			$serial[] = (($options["expires"] ? 0x80 : 0x00) | (($date >> 13) & 0x7F));
			$serial[] = ((($date >> 5) & 0xFF));
			$serial[] = ((($date & 0x1F) << 3) | (($productid >> 7) & 0x07));
			$serial[] = ((($productid & 0x7F) << 1) | (($options["product_class"] >> 3) & 0x01));
			$serial[] = ((($options["product_class"] & 0x07) << 5) | (($majorver >> 3) & 0x1F));
			$serial[] = ((($majorver & 0x07) << 5) | (($options["minor_ver"] >> 3) & 0x1F));
			$serial[] = ((($options["minor_ver"] & 0x07) << 5) | ($options["custom_bits"] & 0x1F));

			$serial2 = chr($serial[0]) . chr($serial[1]) . chr($serial[2]) . chr($serial[3]) . chr($serial[4]) . chr($serial[5]) . chr($serial[6]);
			$validate = hash_hmac("sha1", $serial2 . "|" . $userinfo, $options["validate_secret"], true);
			$serial[] = ord($validate[0]);
			$serial[] = ord($validate[1]);
			$serial[] = ord($validate[2]);

			// Calculate the encryption key HMAC SHA-1 of product ID (2 bytes) '|' major version (1 byte) '|' user-specific info (e.g. email).
			$key = hash_hmac("sha1", pack("n", $productid) . "|" . chr($majorver) . "|" . $userinfo, $options["encrypt_secret"], true);

			// Encrypt step.  16 rounds.
			for ($x = 0; $x < 16; $x++)
			{
				// Rotate the bits across the bytes to the right by five.  The last 5-bit chunk becomes the first 5-bit chunk.
				// All data in the serial experiences two unique positions of the encryption key.
				$lastchunk = $serial[9] & 0x1F;
				for ($x2 = 9; $x2; $x2--)  $serial[$x2] = ((($serial[$x2 - 1] & 0x1F) << 3) | (($serial[$x2] >> 5) & 0x07));
				$serial[0] = (($lastchunk << 3) | (($serial[0] >> 5) & 0x07));

				// Apply XOR of the first 10 bytes of the encryption key and add the other 10 bytes to the serial (10 bytes).
				for ($x2 = 0; $x2 < 10; $x2++)  $serial[$x2] = ((($serial[$x2] ^ ord($key[$x2])) + ord($key[$x2 + 10])) & 0xFF);
			}

			// Encode step.  5-bit groups with 2x pattern, mapped to letters, hyphen every 4 characters:
			// 12345 67812 34567 81234
			// 56781 23456 78123 45678
			$serial2 = $options["encode_chars"][(($serial[0] >> 3) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[0] & 0x07) << 2) | (($serial[1] >> 6) & 0x03))];
			$serial2 .= $options["encode_chars"][(($serial[1] >> 1) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[1] & 0x01) << 4) | (($serial[2] >> 4) & 0x0F))];
			$serial2 .= "-";
			$serial2 .= $options["encode_chars"][((($serial[2] & 0x0F) << 1) | (($serial[3] >> 7) & 0x01))];
			$serial2 .= $options["encode_chars"][(($serial[3] >> 2) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[3] & 0x03) << 3) | (($serial[4] >> 5) & 0x07))];
			$serial2 .= $options["encode_chars"][($serial[4] & 0x1F)];
			$serial2 .= "-";

			$serial2 .= $options["encode_chars"][(($serial[5] >> 3) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[5] & 0x07) << 2) | (($serial[6] >> 6) & 0x03))];
			$serial2 .= $options["encode_chars"][(($serial[6] >> 1) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[6] & 0x01) << 4) | (($serial[7] >> 4) & 0x0F))];
			$serial2 .= "-";
			$serial2 .= $options["encode_chars"][((($serial[7] & 0x0F) << 1) | (($serial[8] >> 7) & 0x01))];
			$serial2 .= $options["encode_chars"][(($serial[8] >> 2) & 0x1F)];
			$serial2 .= $options["encode_chars"][((($serial[8] & 0x03) << 3) | (($serial[9] >> 5) & 0x07))];
			$serial2 .= $options["encode_chars"][($serial[9] & 0x1F)];

			return array("success" => true, "serial" => $serial2);
		}

		public static function Verify($serial, $productid = -1, $majorver = -1, $userinfo = false, $options = array())
		{
			if (!is_string($serial))  return array("success" => false, "error" => self::SNTranslate("Invalid serial number format.  Expected a string."), "errorcode" => "invalid_serial_format");
			if (isset($options["encode_chars"]))  $options["decode_chars"] = $options["encode_chars"];
			if (!isset($options["decode_chars"]) || !is_string($options["decode_chars"]) || strlen($options["decode_chars"]) < 32)  $options["decode_chars"] = "abcdefghijkmnpqrstuvwxyz23456789";

			$charmap = array();
			for ($x = 0; $x < 32; $x++)  $charmap[$options["decode_chars"][$x]] = $x;
			if (count($charmap) != 32)  return array("success" => false, "error" => self::SNTranslate("Invalid decoding character list.  Expected 32 unique characters."), "errorcode" => "invalid_decode_chars");

			// Decode step.
			$origserial = array();
			$serial2 = array();
			$y = strlen($serial);
			for ($x = 0; $x < $y; $x++)
			{
				if (isset($charmap[$serial[$x]]))
				{
					$origserial[] = $serial[$x];
					$serial2[] = $charmap[$serial[$x]];
				}
			}

			if (count($serial2) != 16)  return array("success" => false, "error" => self::SNTranslate("Invalid serial number."), "errorcode" => "invalid_serial");

			$origserial = $origserial[0] . $origserial[1] . $origserial[2] . $origserial[3] . "-" . $origserial[4] . $origserial[5] . $origserial[6] . $origserial[7] . "-" . $origserial[8] . $origserial[9] . $origserial[10] . $origserial[11] . "-" . $origserial[12] . $origserial[13] . $origserial[14] . $origserial[15];

			// If just verifying a valid character sequence, then return success.
			if (isset($options["encrypt_secret"]))  $options["decrypt_secret"] = $options["encrypt_secret"];
			if (!isset($options["decrypt_secret"]))  return array("success" => true, "serial_num" => $origserial);

			if (!is_string($options["decrypt_secret"]))  return array("success" => false, "error" => self::SNTranslate("Invalid decryption HMAC secret."), "errorcode" => "invalid_decrypt_secret");
			if ($productid < 0 || $productid > 1023)  return array("success" => false, "error" => self::SNTranslate("Invalid product ID.  Outside valid range."), "errorcode" => "invalid_product_id");
			if ($majorver < 0 || $majorver > 255)  return array("success" => false, "error" => self::SNTranslate("Invalid major version.  Outside valid range."), "errorcode" => "invalid_major_ver");
			if ($userinfo === false)  return array("success" => false, "error" => self::SNTranslate("Missing user info."), "errorcode" => "missing_userinfo");

			if (is_array($userinfo))  $userinfo = implode("|", $userinfo);
			else  $userinfo = (string)$userinfo;

			// Move 5-bit chunks into 8-bit chunks.
			// 12345 67812 34567 81234
			// 56781 23456 78123 45678
			$serial = array();
			$serial[] = (($serial2[0] << 3) | (($serial2[1] >> 2) & 0x07));
			$serial[] = ((($serial2[1] & 0x03) << 6) | ($serial2[2] << 1) | ($serial2[3] >> 4));
			$serial[] = ((($serial2[3] & 0x0F) << 4) | (($serial2[4] >> 1)));
			$serial[] = ((($serial2[4] & 0x01) << 7) | ($serial2[5] << 2) | ($serial2[6] >> 3));
			$serial[] = ((($serial2[6] & 0x07) << 5) | $serial2[7]);

			$serial[] = (($serial2[8] << 3) | (($serial2[9] >> 2) & 0x07));
			$serial[] = ((($serial2[9] & 0x03) << 6) | ($serial2[10] << 1) | ($serial2[11] >> 4));
			$serial[] = ((($serial2[11] & 0x0F) << 4) | (($serial2[12] >> 1)));
			$serial[] = ((($serial2[12] & 0x01) << 7) | ($serial2[13] << 2) | ($serial2[14] >> 3));
			$serial[] = ((($serial2[14] & 0x07) << 5) | $serial2[15]);

			// Calculate the encryption key HMAC SHA-1 of product ID (2 bytes) '|' major version (1 byte) '|' user-specific info (e.g. email).
			$key = hash_hmac("sha1", pack("n", $productid) . "|" . chr($majorver) . "|" . $userinfo, $options["decrypt_secret"], true);

			// Decrypt step.  16 rounds.
			for ($x = 16; $x; $x--)
			{
				// Apply XOR of each byte of the decryption key (10 bytes * 2) to the serial (10 bytes).
				for ($x2 = 0; $x2 < 10; $x2++)
				{
					$serial[$x2] -= ord($key[$x2 + 10]);
					if ($serial[$x2] < 0)  $serial[$x2] += 256;

					$serial[$x2] = $serial[$x2] ^ ord($key[$x2]);
				}

				// Rotate the bits across the bytes to the left by five.  The first 5-bit chunk becomes the last 5-bit chunk.
				// All data in the serial experiences two unique positions of the decryption key.
				$firstchunk = (($serial[0] >> 3) & 0x1F);
				for ($x2 = 0; $x2 < 9; $x2++)  $serial[$x2] = ((($serial[$x2] & 0x07) << 5) | (($serial[$x2 + 1] >> 3) & 0x1F));
				$serial[9] = ((($serial[9] & 0x07) << 5) | $firstchunk);
			}

			// Verify decryption.
			if (isset($options["validate_secret"]))
			{
				if (!is_string($options["validate_secret"]))  return array("success" => false, "error" => self::SNTranslate("Invalid validation HMAC secret."), "errorcode" => "invalid_validate_secret");

				$serial2 = chr($serial[0]) . chr($serial[1]) . chr($serial[2]) . chr($serial[3]) . chr($serial[4]) . chr($serial[5]) . chr($serial[6]);
				$validate = hash_hmac("sha1", $serial2 . "|" . $userinfo, $options["validate_secret"], true);

				if ($serial[7] !== ord($validate[0]) || $serial[8] !== ord($validate[1]) || $serial[9] !== ord($validate[2]))  return array("success" => false, "error" => self::SNTranslate("Invalid serial number."), "errorcode" => "invalid_serial");
			}

			// See Generate() for the format of each key (big-endian, 10 bytes).
			$result = array(
				"success" => true,
				"serial_num" => $origserial,
				"userinfo" => $userinfo,
				"expires" => ($serial[0] & 0x80 ? true : false),
				"date" => ((($serial[0] & 0x7F) << 13) | ($serial[1] << 5) | ($serial[2] >> 3)) * 86400,
				"product_id" => ((($serial[2] & 0x07) << 7) | ($serial[3] >> 1)),
				"product_class" => ((($serial[3] & 0x01) << 3) | ($serial[4] >> 5)),
				"major_ver" => ((($serial[4] & 0x1F) << 3) | ($serial[5] >> 5)),
				"minor_ver" => ((($serial[5] & 0x1F) << 3) | ($serial[6] >> 5)),
				"custom_bits" => ($serial[6] & 0x1F),
			);

			if ($result["product_id"] !== $productid || $result["major_ver"] !== $majorver)  return array("success" => false, "error" => self::SNTranslate("Invalid serial number."), "errorcode" => "invalid_serial");

			return $result;
		}

		protected static function SNTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>