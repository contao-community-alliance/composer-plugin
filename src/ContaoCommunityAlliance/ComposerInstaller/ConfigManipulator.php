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
use Composer\Json\JsonFile;
use Composer\Package\Version\VersionParser;

/**
 * Manipulate the root composer.json on the fly.
 */
class ConfigManipulator
{
	static public function run(IOInterface $io, Composer $composer)
	{
		/** @var \Composer\Package\RootPackage $package */
		$package = $composer->getPackage();

		// load constants
		$root = Plugin::getContaoRoot($package);

		$messages     = array();
		$jsonModified = false;
		$configFile   = new JsonFile('composer.json');
		$configJson   = $configFile->read();

		// NOTE: we do not need our hard-coded scripts anymore, since we have a plugin

		$jsonModified |= static::removeObsoleteScripts($configJson, $messages);
		$jsonModified |= static::removeObsoleteConfigEntries($configJson, $messages);
		$jsonModified |= static::removeObsoleteRepositories($configJson, $messages);
		$jsonModified |= static::removeObsoleteRequires($configJson, $messages);
		$jsonModified |= $contaoVersionUpdated = static::updateContaoVersion(
			$configJson,
			$messages,
			$composer
		);
		$jsonModified |= static::updateProvides($configJson, $messages);

		if ($contaoVersionUpdated) {
			// run all runonces after contao version changed
			RunonceManager::addAllRunonces($composer);
			RunonceManager::createRunonce($io, $root);
		}

		if ($jsonModified) {
			$configFile->write($configJson);
		}
		if (count($messages)) {
			$exception = null;
			foreach (array_reverse($messages) as $message) {
				$exception = new ConfigUpdateException($message, 0, $exception);
			}
			throw $exception;
		}
	}

	/**
	 * Remove obsolete event scripts from the root composer.json.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteScripts(&$configJson, &$messages)
	{
		$jsonModified = false;

		// remove old installer scripts
		$eventScripts = array(
			'pre-update-cmd'     => array(
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateContaoPackage',
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateComposerConfig',
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
			),
			'post-update-cmd'    => array(
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::createRunonce',
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate',
			),
			'post-autoload-dump' => array(
				'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
			),
		);
		foreach ($eventScripts as $key => $scripts) {
			foreach ($scripts as $script) {
				if (isset($configJson['scripts'][$key])) {
					if (is_array($configJson['scripts'][$key])) {
						$index = array_search($script, $configJson['scripts'][$key]);
						if ($index !== false) {
							unset($configJson['scripts'][$key][$index]);
							if (empty($configJson['scripts'][$key])) {
								unset($configJson['scripts'][$key]);
							}

							$jsonModified = true;
							$messages[]   = 'obsolete ' . $key . ' script ' . $script .
								' was removed from root composer.json';
						}
					}
					else if ($configJson['scripts'][$key] == $script) {
						unset($configJson['scripts'][$key]);

						$jsonModified = true;
						$messages[]   = 'obsolete ' . $key . ' script ' . $script .
							' was removed from root composer.json';
					}
				}
			}
		}

		return $jsonModified;
	}

	/**
	 * Remove obsolete configuration entries from the root composer.json.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteConfigEntries(&$configJson, &$messages)
	{
		$jsonModified = false;

		if (isset($configJson['extra']['contao']['artifactPath'])) {
			unset($configJson['extra']['contao']['artifactPath']);

			$jsonModified = true;
			$messages[]   = 'obsolete config entry { extra: { contao: { artifactPath: ... } } } ' .
				'was removed from root composer.json';
		}

		return $jsonModified;
	}

	/**
	 * Remove obsolete repositories from the root composer.json.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteRepositories(&$configJson, &$messages)
	{
		if (!isset($configJson['repositories'])) {
			return false;
		}

		$jsonModified = false;

		// filter the artifact and legacy packagist repositories
		foreach ($configJson['repositories'] as $index => $repository) {
			if (
				$repository['type'] == 'artifact' &&
				preg_match('~(^packages|/packages)$~', rtrim($repository['url'], '/'))
			) {
				unset($configJson['repositories'][$index]);

				$jsonModified = true;
				$messages[]   = 'obsolete artifact repository was removed from root composer.json';
			}

			if (
				$repository['type'] == 'composer' &&
				(
					$repository['url'] == 'http://legacy-packages-via.contao-community-alliance.org/' ||
					$repository['url'] == 'https://legacy-packages-via.contao-community-alliance.org/'
				)
			) {
				unset($configJson['repositories'][$index]);

				$jsonModified = true;
				$messages[]   = 'obsolete legacy packages repository was removed from root composer.json';
			}
		}

		if ($jsonModified) {
			$configJson['repositories'] = array_values($configJson['repositories']);
		}

		return $jsonModified;
	}

	/**
	 * Remove obsolete requires from the root composer.json.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteRequires(&$configJson, &$messages)
	{
		$jsonModified = false;

		// remove contao-community-alliance/composer dependency
		if (isset($configJson['require']['contao-community-alliance/composer'])) {
			unset($configJson['require']['contao-community-alliance/composer']);

			$jsonModified = true;
			$messages[]   = 'obsolete require contao-community-alliance/composer ' .
				'was removed from root composer.json';
		}

		return $jsonModified;
	}

	/**
	 * Update the Contao Version in the root composer.json, if it has changed.
	 *
	 * @return boolean
	 */
	static public function updateContaoVersion(
		&$configJson,
		&$messages,
		Composer $composer
	) {
		$jsonModified = false;

		// update contao version
		$package       = $composer->getPackage();
		$versionParser = new VersionParser();
		$version       = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);
		if ($package->getVersion() !== $prettyVersion) {
			$configJson['version'] = $version;

			$jsonModified = true;
			$messages[]   = sprintf(
				'Contao version changed from <info>%s</info> to <info>%s</info>!',
				$package->getPrettyVersion(),
				$version
			);
		}

		return $jsonModified;
	}

	/**
	 * Update the provides in the root composer.json, if they have changed.
	 *
	 * @return boolean
	 */
	static public function updateProvides(&$configJson, &$messages)
	{
		$jsonModified = false;
		$file         = false;

		// add provides
		switch (VERSION) {
			case '2.11':
				$file = TL_ROOT . '/plugins/swiftmailer/VERSION';
				break;
			case '3.0':
				$file = TL_ROOT . '/system/vendor/swiftmailer/VERSION';
				break;
			case '3.1':
			case '3.2':
				$file = TL_ROOT . '/system/modules/core/vendor/swiftmailer/VERSION';
				break;
			default:
				$swiftVersion = '0';
		}

		if ($file && is_file($file)) {
			$swiftVersion = file_get_contents($file);
			$swiftVersion = substr($swiftVersion, 6);
			$swiftVersion = trim($swiftVersion);
		}
		else {
			$swiftVersion = '0';
		}
		if (
			!isset($configJson['provide']['swiftmailer/swiftmailer']) ||
			$configJson['provide']['swiftmailer/swiftmailer'] != $swiftVersion
		) {
			$configJson['provide']['swiftmailer/swiftmailer'] = $swiftVersion;

			$jsonModified = true;
			$messages[]   = sprintf(
				'Provided swiftmailer version changed to <info>%s</info>!',
				$swiftVersion
			);
		}

		return $jsonModified;
	}
}
