<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * The nextcloud/ocp package ships the OCP/NCU API stubs but declares no
 * autoload section, so `OCP\...` classes are not registered by Composer.
 * Inside a real Nextcloud these come from the server; under PHPUnit we have
 * to map them ourselves, otherwise anything that touches an OCP base class
 * (QBMapper, Controller, ...) fatals with "class not found".
 */
$autoload = require __DIR__ . '/../vendor/autoload.php';

// The app builds with classmap-authoritative, which makes the loader answer
// "not found" for anything outside the generated classmap without consulting
// PSR-4 rules. Turn it off for the test run so the mapping below is honoured.
$autoload->setClassMapAuthoritative(false);

$ocpRoot = __DIR__ . '/../vendor/nextcloud/ocp';
foreach (['OCP', 'NCU'] as $namespace) {
	$dir = $ocpRoot . '/' . $namespace;
	if (is_dir($dir)) {
		$autoload->addPsr4($namespace . '\\', $dir);
	}
}

return $autoload;
