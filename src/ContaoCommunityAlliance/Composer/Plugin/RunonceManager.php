<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Dominik Zogg <dominik.zogg@gmail.com>
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;

/**
 * Remember runonce files that found while installing packages and
 * finally create a single TL_ROOT/system/runonce.php file.
 */
class RunonceManager
{
	/**
	 * List of runonce files that was found in the installed/updated packages.
	 *
	 * @var array
	 */
	static public $runonces = array();

	/**
	 * Create the global runonce file TL_ROOT/system/runonce.php file if required.
	 *
	 * @param IOInterface $inputOutput   The composer io stream.
	 * @param string      $root The Contao installation root path.
	 */
	static public function createRunonce(IOInterface $inputOutput, $root)
	{
		// create runonce
		$runonces = array_unique(static::$runonces);
		if (count($runonces)) {
			$file  = 'system/runonce.php';
			$index = 0;
			while (file_exists($root . DIRECTORY_SEPARATOR . $file)) {
				$index++;
				$file = 'system/runonce_' . $index . '.php';
			}
			if ($index > 0) {
				rename(
					$root . '/system/runonce.php',
					$root . DIRECTORY_SEPARATOR . $file
				);
				array_unshift(
					$runonces,
					$file
				);
			}

			$array = var_export($runonces, true);

			$runonce = <<<EOF
<?php

\$executor = new \ContaoCommunityAlliance\Composer\Plugin\RunonceExecutor();
\$executor->run($array);

EOF;
			file_put_contents($root . '/system/runonce.php', $runonce);

			$inputOutput->write(
				sprintf(
					'<info>Runonce created with %d updates</info>',
					count($runonces)
				)
			);
			if ($inputOutput->isVerbose()) {
				foreach ($runonces as $runonce) {
					$inputOutput->write('  - ' . $runonce);
				}
			}
		}
	}

	/**
	 * Add a runonce file by path.
	 *
	 * @param string $path The absolute runonce file path.
	 */
	static public function addRunonce($path)
	{
		static::$runonces[] = $path;
	}

	/**
	 * Add runonce files from a package.
	 *
	 * @param PackageInterface $package
	 */
	static public function addRunonces(PackageInterface $package, $installPath)
	{
		$extra = $package->getExtra();
		if (isset($extra['contao']['runonce'])) {
			$runonces = (array) $extra['contao']['runonce'];

			foreach ($runonces as $file) {
				static::addRunonce($installPath . DIRECTORY_SEPARATOR . $file);
			}
		}
	}

	/**
	 * Update all runonce files from all installed packages.
	 *
	 * @param Composer $composer
	 */
	static public function addAllRunonces(Composer $composer)
	{
		$installationManager = $composer->getInstallationManager();
		$repositoryManager   = $composer->getRepositoryManager();
		$localRepository     = $repositoryManager->getLocalRepository();
		$packages            = $localRepository->getPackages();

		/** @var PackageInterface $package */
		foreach ($packages as $package) {
			if (!$package instanceof AliasPackage) {
				$installer = $installationManager->getInstaller($package->getType());
				static::addRunonces($package, $installer->getInstallPath($package));
			}
		}
	}
}
