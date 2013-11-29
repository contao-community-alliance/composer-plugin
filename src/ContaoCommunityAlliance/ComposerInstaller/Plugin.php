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

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class Plugin
	implements PluginInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function activate(Composer $composer, IOInterface $io)
	{
		$installationManager = $composer->getInstallationManager();

		$installer = new ModuleInstaller($io, $composer);
		$installationManager->addInstaller($installer);
	}

	/**
	 * Detect the contao installation root and set the TL_ROOT constant
	 * if not already exist (from previous run or when run within contao).
	 * Also detect the contao version and local configuration settings.
	 *
	 * @param RootPackageInterface $package
	 *
	 * @return string
	 */
	static public function getContaoRoot(RootPackageInterface $package)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());

			$extra = $package->getExtra();
			$cwd = getcwd();

			if (!empty($extra['contao']['root'])) {
				$root = $cwd . DIRECTORY_SEPARATOR . $extra['contao']['root'];
			}
			// test, do we have the core within vendor/contao/core.
			else {
				$vendorRoot = $cwd . DIRECTORY_SEPARATOR .
					'vendor' . DIRECTORY_SEPARATOR .
					'contao' . DIRECTORY_SEPARATOR .
					'core';

				if (is_dir($vendorRoot)) {
					$root = $vendorRoot;
				}
			}

			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		$systemDir = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
		$configDir = $systemDir . 'config' . DIRECTORY_SEPARATOR;

		if (!defined('VERSION')) {
			// Contao 3+
			if (file_exists(
				$constantsFile = $configDir . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			// Contao 2+
			else if (file_exists(
				$constantsFile = $systemDir . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			else {
				throw new \RuntimeException('Could not find constants.php in ' . $root);
			}
		}

		if (empty($GLOBALS['TL_CONFIG'])) {
			if (version_compare(VERSION, '3', '>=')) {
				// load default.php
				require_once($configDir . 'default.php');
			}
			else {
				// load config.php
				require_once($configDir . 'config.php');
			}

			// load localconfig.php
			$file = $configDir . 'localconfig.php';
			if (file_exists($file)) {
				require_once($file);
			}
		}

		return $root;
	}
}
