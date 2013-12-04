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
use Composer\Json\JsonFile;
use Composer\Package\Version\VersionParser;

/**
 * Manipulate the root composer.json on the fly.
 */
class ConfigManipulator
{
	/**
	 * Run all configuration updates.
	 *
	 * @param IOInterface $inputOutput
	 * @param Composer    $composer
	 *
	 * @throws ConfigUpdateException
	 * @throws null
	 */
	static public function run()
	{
		$messages     = array();
		$configFile   = new JsonFile('composer.json');
		$configJson   = $configFile->read();

		// NOTE: we do not need our hard-coded scripts anymore, since we have a plugin

		$jsonModified = static::runUpdates($configJson, $messages);

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

	static public function runUpdates(
		&$configJson,
		&$messages
	) {
		$jsonModified = false;

		$jsonModified = static::removeObsoleteScripts($configJson, $messages) || $jsonModified;
		$jsonModified = static::removeObsoleteConfigEntries($configJson, $messages) || $jsonModified;
		$jsonModified = static::removeObsoleteRepositories($configJson, $messages) || $jsonModified;
		$jsonModified = static::removeObsoleteRequires($configJson, $messages) || $jsonModified;
		$jsonModified = static::removeObsoleteContaoVersion($configJson, $messages) || $jsonModified;

		// TODO we need a new contao version change check!!!
		/*
		if ($contaoVersionUpdated) {
			// run all runonces after contao version changed
			RunonceManager::addAllRunonces($composer);
			RunonceManager::createRunonce($inputOutput, $root);
		}
		*/

		return $jsonModified;
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
				$jsonModified = static::removeObsoleteScript($key, $script, $configJson, $messages) ||
					$jsonModified;
			}
		}

		if (isset($configJson['scripts']) && empty($configJson['scripts'])) {
			unset($configJson['scripts']);
			$jsonModified = true;
		}

		return $jsonModified;
	}

	/**
	 * Remove obsolete event script.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteScript($key, $script, &$configJson, &$messages)
	{
		if (isset($configJson['scripts'][$key])) {
			if (is_array($configJson['scripts'][$key])) {
				$index = array_search($script, $configJson['scripts'][$key]);
				if ($index !== false) {
					unset($configJson['scripts'][$key][$index]);
					if (empty($configJson['scripts'][$key])) {
						unset($configJson['scripts'][$key]);
					}

					$messages[]   = 'obsolete ' . $key . ' script ' . $script .
						' was removed from root composer.json';
					return true;
				}
			}
			else if ($configJson['scripts'][$key] == $script) {
				unset($configJson['scripts'][$key]);

				$messages[]   = 'obsolete ' . $key . ' script ' . $script .
					' was removed from root composer.json';
				return true;
			}
		}

		return false;
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
		if (
			isset($configJson['require']['contao-community-alliance/composer']) &&
			(
				$configJson['require']['contao-community-alliance/composer'] == 'dev-master@dev' ||
				$configJson['require']['contao-community-alliance/composer'] == '*'
			)
		) {
			unset($configJson['require']['contao-community-alliance/composer']);

			$jsonModified = true;
			$messages[]   = 'obsolete require contao-community-alliance/composer ' .
				'was removed from root composer.json';
		}

		return $jsonModified;
	}

	/**
	 * Remove the Contao Version and additional information from the root composer.json.
	 *
	 * @return boolean
	 */
	static public function removeObsoleteContaoVersion(&$configJson, &$messages)
	{
		$jsonModified = false;

		if (
			isset($configJson['name']) &&
			isset($configJson['type']) &&
			$configJson['name'] == 'contao/core' &&
			$configJson['type'] == 'metapackage'
		) {
			foreach (array('name', 'description', 'type', 'license', 'version') as $key) {
				if (isset($configJson[$key])) {
					unset($configJson[$key]);
				}
			}

			if (isset($configJson['provide']['swiftmailer/swiftmailer'])) {
				unset($configJson['provide']['swiftmailer/swiftmailer']);

				if (empty($configJson['provide'])) {
					unset($configJson['provide']);
				}
			}

			$jsonModified = true;
			$messages[]   = 'obsolete contao version and meta information ' .
				'was removed from root composer.json!';
		}

		return $jsonModified;
	}
}
