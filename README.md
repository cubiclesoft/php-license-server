PHP-based Software License Server
=================================

A high-performance license server system service for creating and managing products, major versions, and software licenses for the purpose of selling installable software products.  Comes with a SDK and command-line tool.  Works anywhere that PHP runs.

For when you need a robust license server but want to just get back to writing software.

See it in action:

* [License Server Demo/Test Site](https://license-server-demo.cubiclesoft.com/)
* [File Tracker](https://file-tracker.cubiclesoft.com/)

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/)

Features
--------

* Generate unique [encrypted serial numbers]() on a per-major version basis.  Each encrypted serial number contains:  Date created or date the license expires, product ID, product classification, major and minor version numbers, per-application custom bits (e.g. a hardware hash, digital/physical copy, purchase avenue, single/multi-user license), and per-user hash checksum bits.
* Quickly and easily manage products (up to 1,024), major versions (up to 256 per product), product classes (up to 16 per major version - e.g. Standard, Pro, Enterprise), and software licenses (unlimited).
* Supports per-license activation limits, download limits, and revoking the occasional rogue license.
* Supports various common customizations (e.g. storing an external order number alongside each license, setting the character encoding scheme for each major version's serials, etc).
* Can generate complex order numbers designed to keep business finances private while still supporting account reconciliation of transactions (e.g. SEC GAAP compliance).
* Optional history logging for all license actions.
* Has a PHP SDK for communicating directly with the service.
* Also comes with a complete, question/answer enabled command-line interface.  Nothing to compile.  Cross platform.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your project.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

Place the contents of this repository in a secure location on a host.  Note that for performance reasons there is no security for the license server.  However, it only starts as a localhost-only server on port 24276, which means someone has to gain access to the host to make changes directly.

To start the server, simply run:

```
php server.php
```

In a separate window/terminal, run:

```
php tools.php
```

Which utilizes the PHP SDK to provide a guided command-line interface for setting up products, versions, and managing licenses.

The PHP SDK is located at `support/sdk_license_server.php` and is standalone (i.e. you can copy just that file to your application and it'll work fine).  Be sure to check out the [SDK documentation](https://github.com/cubiclesoft/php-license-server/blob/master/docs/sdk_license_server.md).  Use the PHP SDK to provide the interface between a web server running PHP and the license server to do things such as creating a license after completing a purchase, retrieving the list of licenses that the user owns, and activating/deactivating licenses when the software is installed/uninstalled.  Example usage of the SDK can be found in `tools.php`.

To install the server so that it runs at boot, first stop any running instances and then run the following as root/Administrator:

```
php server.php install
```

Then start the `php-license-server` system service using standard OS tools.

Performance
-----------

For additional performance, [install the PECL libev extension](https://pecl.php.net/package/ev) via `pecl install ev` and [raise the system ulimit](https://stackoverflow.com/questions/34588/how-do-i-change-the-number-of-open-files-limit-in-linux) to support thousands of simultaneous connections.  Since connections are generally short-lived, performance gains will only be noticeable under heavy system load.  Also, the PECL libev extension itself does not function properly on Windows and is therefore ignored by this software.

The included `concurrency_test.php` is a basic benchmarking script.  In concurrency testing, the script verified over 6,150 serial numbers per second (verification only) and a separate run of the script activated over 4,275 serial numbers per second (activation only) on an Intel 6th Gen Core i7 running Windows 10 Pro (64-bit).  The underlying serial number verification class can validate up to 27,000 serials numbers per second in a single thread on the same hardware.

While the `concurrency_test.php` script can be run standalone, it is designed to be run with [CubicleSoft PHP concurrency tester](https://github.com/cubiclesoft/php-concurrency-tester/).
