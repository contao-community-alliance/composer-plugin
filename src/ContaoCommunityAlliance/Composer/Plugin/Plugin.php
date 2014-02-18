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
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ArtifactRepository;
use Composer\Repository\ComposerRepository;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class Plugin
	implements PluginInterface, EventSubscriberInterface
{
	/**
	 * @var Composer
	 */
	protected $composer;

	/**
	 * @var IOInterface
	 */
	protected $inputOutput;

	/**
	 * {@inheritdoc}
	 */
	public function activate(Composer $composer, IOInterface $inputOutput)
	{
		$this->composer    = $composer;
		$this->inputOutput = $inputOutput;

		$installationManager = $composer->getInstallationManager();

		$config = $composer->getConfig();
		if ($config->get('preferred-install') == 'dist') {
			$installer = new CopyInstaller($inputOutput, $composer, $this);
		}
		else {
			$installer = new SymlinkInstaller($inputOutput, $composer, $this);
		}
		$installationManager->addInstaller($installer);

		$this->injectContaoCore();
		$this->injectRequires();
		$this->addLocalArtifactsRepository();
		$this->addLegacyPackagesRepository();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::COMMAND            => 'handleCommand',
			ScriptEvents::POST_UPDATE_CMD    => 'handleScriptEvent',
			ScriptEvents::POST_AUTOLOAD_DUMP => 'handleScriptEvent',
			PluginEvents::PRE_FILE_DOWNLOAD  => 'handlePreDownload',
		);
	}

	/**
	 * Inject the swiftMailer version into the Contao package.
	 *
	 * @param string          $contaoRoot
	 *
	 * @param CompletePackage $package
	 *
	 * @return void
	 */
	protected function injectSwiftMailer($contaoRoot, CompletePackage $package)
	{
		$provides      = $package->getProvides();
		$versionParser = new VersionParser();

		// detect provided Swift Mailer version
		switch (VERSION) {
			case '2.11':
				$file = $contaoRoot . '/plugins/swiftmailer/VERSION';
				break;
			case '3.0':
				$file = $contaoRoot . '/system/vendor/swiftmailer/VERSION';
				break;
			case '3.1':
			case '3.2':
				$file = $contaoRoot . '/system/modules/core/vendor/swiftmailer/VERSION';
				break;
			default:
				$file = false;
		}

		if ($file && is_file($file)) {
			$prettySwiftVersion = file_get_contents($file);
			$prettySwiftVersion = substr($prettySwiftVersion, 6);
			$prettySwiftVersion = trim($prettySwiftVersion);

			$swiftVersion = $versionParser->normalize($prettySwiftVersion);

			$swiftConstraint = new VersionConstraint('==', $swiftVersion);
			$swiftConstraint->setPrettyString($swiftVersion);

			$swiftLink = new Link(
				'contao/core',
				'swiftmailer/swiftmailer',
				$swiftConstraint,
				'provides',
				$swiftVersion
			);

			$provides['swiftmailer/swiftmailer'] = $swiftLink;
		}

		$package->setProvides($provides);
	}

	protected function prepareContaoVersion($version, $build)
	{
		// Regular stable build
		if (is_numeric($build)) {
			return $version . '.' . $build;
		}

		// Standard pre-release
		if (preg_match('{^(alpha|beta|RC)?(\d+)?$}i', $build)) {
			return $version . '.' . $build;
		}

		// Must be a custom patched release with - suffix.
		if (preg_match('{^(\d+)[-]}i', $build, $matches)) {
			return $version . '.' . $matches[1];
		}

		throw new \RuntimeException('Invalid version: ' . $version . '.' . $build);
	}

	/**
	 * Inject the currently installed contao/core as metapackage.
	 *
	 * @return void
	 */
	public function injectContaoCore()
	{
		$root              = static::getContaoRoot($this->composer->getPackage());
		$repositoryManager = $this->composer->getRepositoryManager();
		$localRepository   = $repositoryManager->getLocalRepository();

		$versionParser = new VersionParser();
		$prettyVersion = $this->prepareContaoVersion(VERSION, BUILD);
		$version       = $versionParser->normalize($prettyVersion);

		/** @var PackageInterface $localPackage */
		foreach ($localRepository->getPackages() as $localPackage) {
			if ($localPackage->getName() == 'contao/core') {
				if ($localPackage->getType() != 'metapackage') {
					// stop if the contao package is required somehow
					// and must not be injected
					return;
				}
				else if ($localPackage->getVersion() == $version) {
					// stop if the virtual contao package is already injected
					return;
				}
				else {
					$localRepository->removePackage($localPackage);
				}
			}
		}

		$contaoVersion = VERSION . '.' . BUILD;
		$contaoCore    = new CompletePackage('contao/core', $version, $prettyVersion);
		$contaoCore->setType('metapackage');
		$contaoCore->setDistType('zip');
		$contaoCore->setDistUrl('https://github.com/contao/core/archive/' . $contaoVersion . '.zip');
		$contaoCore->setDistReference($contaoVersion);
		$contaoCore->setDistSha1Checksum($contaoVersion);
		$contaoCore->setInstallationSource('dist');

		$this->injectSwiftMailer($root, $contaoCore);

		$clientConstraint = new EmptyConstraint();
		$clientConstraint->setPrettyString('*');
		$clientLink = new Link(
			'contao/core',
			'contao-community-alliance/composer',
			$clientConstraint,
			'requires',
			'*'
		);
		$contaoCore->setRequires(array('contao-community-alliance/composer' => $clientLink));

		$localRepository->addPackage($contaoCore);
	}

	/**
	 * Inject the contao/core as permanent requirement into the root package.
	 *
	 * @return void
	 */
	public function injectRequires()
	{
		$package  = $this->composer->getPackage();
		$requires = $package->getRequires();

		if (!isset($requires['contao/core'])) {
			// load here to make sure the VERSION constant exists
			static::getContaoRoot($this->composer->getPackage());

			$versionParser = new VersionParser();
			$prettyVersion = $this->prepareContaoVersion(VERSION, BUILD);
			$version = $versionParser->normalize($prettyVersion);

			$constraint = new VersionConstraint('==', $version);
			$constraint->setPrettyString($prettyVersion);
			$requires['contao/core'] = new Link(
				'contao/core',
				'contao/core',
				$constraint,
				'requires',
				$prettyVersion
			);
			$package->setRequires($requires);
		}
	}

	/**
	 * Add the local artifacts repository to the composer installation.
	 *
	 * @return void
	 */
	public function addLocalArtifactsRepository()
	{
		$contaoRoot             = static::getContaoRoot($this->composer->getPackage());
		$artifactRepositoryPath = $contaoRoot . DIRECTORY_SEPARATOR .
			'composer' . DIRECTORY_SEPARATOR .
			'packages';
		if (is_dir($artifactRepositoryPath)) {
			$artifactRepository = new ArtifactRepository(
				array('url' => $artifactRepositoryPath),
				$this->inputOutput
			);
			$this->composer->getRepositoryManager()
				->addRepository($artifactRepository);
		}
	}

	/**
	 * Add the legacy Contao packages repository to the composer installation.
	 *
	 * @return void
	 */
	public function addLegacyPackagesRepository()
	{
		$legacyPackagistRepository = new ComposerRepository(
			array('url' => 'http://legacy-packages-via.contao-community-alliance.org/'),
			$this->inputOutput,
			$this->composer->getConfig(),
			$this->composer->getEventDispatcher()
		);
		$this->composer->getRepositoryManager()
			->addRepository($legacyPackagistRepository);
	}

	/**
	 * Handle command events.
	 *
	 * @param CommandEvent $event
	 *
	 * @return void
	 */
	public function handleCommand(CommandEvent $event)
	{
		switch ($event->getCommandName()) {
			case 'update':
				ConfigManipulator::run();
				break;

			default:
		}
	}

	/**
	 * Handle script events.
	 *
	 * @param Event $event
	 *
	 * @return void
	 */
	public function handleScriptEvent(Event $event)
	{
		switch ($event->getName()) {
			case ScriptEvents::POST_UPDATE_CMD:
				$package = $this->composer->getPackage();
				$root    = static::getContaoRoot($package);

				$this->createRunonce($this->inputOutput, $root);
				$this->cleanCache($this->inputOutput, $root);
				break;

			case ScriptEvents::POST_AUTOLOAD_DUMP:
				$this->cleanLocalconfig();
				break;

			default:
		}
	}

	/**
	 * Create the global runonce.php after updates has been installed.
	 *
	 * @param IOInterface $inputOutput
	 *
	 * @param string      $root The contao installation root.
	 */
	public function createRunonce(IOInterface $inputOutput, $root)
	{
		RunonceManager::createRunonce($inputOutput, $root);
	}

	/**
	 * Clean the internal cache of Contao after updates has been installed.
	 *
	 * @param IOInterface $inputOutput
	 *
	 * @param string      $root The contao installation root.
	 */
	public function cleanCache(IOInterface $inputOutput, $root)
	{
		// clean cache
		$filesystem = new Filesystem();
		foreach (array('config', 'dca', 'language', 'sql') as $dir) {
			$cache = $root . '/system/cache/' . $dir;
			if (is_dir($cache)) {
				$inputOutput->write(
					sprintf(
						'<info>Clean contao internal %s cache</info>',
						$dir
					)
				);
				$filesystem->removeDirectory($cache);
			}
		}
	}

	public function cleanLocalconfig()
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$localconfig = $root . '/system/config/localconfig.php';
		if (file_exists($localconfig)) {
			$lines    = file($localconfig);
			$remove   = false;
			$modified = false;
			foreach ($lines as $index => $line) {
				$tline = trim($line);
				if ($tline == '### COMPOSER CLASSES START ###') {
					$modified = true;
					$remove   = true;
					unset($lines[$index]);
				}
				else if ($tline == '### COMPOSER CLASSES STOP ###') {
					$remove = false;
					unset($lines[$index]);
				}
				else if ($remove || $tline == '?>') {
					unset($lines[$index]);
				}
			}

			if ($modified) {
				$file = implode('', $lines);
				$file = rtrim($file);

				file_put_contents($root . '/system/config/localconfig.php', $file);
			}
		}
	}

	/**
	 * @draft
	 *
	 * @param PreFileDownloadEvent $event
	 */
	public function handlePreDownload()
	{
		// TODO: handle the pre download event.
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
			$cwd   = getcwd();

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

		static::detectVersion($systemDir, $configDir, $root);
		static::loadConfig($configDir);

		return $root;
	}

	/**
	 * Detect the installed Contao version.
	 *
	 * @param $systemDir
	 *
	 * @param $configDir
	 *
	 * @param $root
	 *
	 * @throws \RuntimeException
	 */
	static protected function detectVersion($systemDir, $configDir, $root)
	{
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
	}

	/**
	 * Load the configuration.
	 *
	 * @param $configDir
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
	 */
	static protected function loadConfig($configDir)
	{
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
	}
}
