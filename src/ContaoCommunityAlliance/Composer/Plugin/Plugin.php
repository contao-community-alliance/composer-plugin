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
use Composer\Script\PackageEvent;
use Composer\Util\Filesystem;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;
use RuntimeException;

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
	 * Path to Contao root.
	 *
	 * @var string
	 */
	protected $contaoRoot;

	/**
	 * @var string
	 */
	protected $contaoVersion;

	/**
	 * @var string
	 */
	protected $contaoBuild;

	/**
	 * @var string
	 */
	protected $contaoUploadPath;

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

		// We must not inject core etc. when the root package itself is being installed via this plugin.
		if (!$installer->supports($composer->getPackage()->getType())) {
			try {
				$this->injectContaoCore();
				$this->injectRequires();
				$this->addLocalArtifactsRepository();
			}
			// @codingStandardsIgnoreStart - Silently ignore the fact that the constants are not found.
			catch (ConstantsNotFoundException $e) {
				// No op.
			}
			// @codingStandardsIgnoreEnd
		}
		$this->addLegacyPackagesRepository();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::COMMAND             => 'handleCommand',
			ScriptEvents::POST_UPDATE_CMD     => 'handleScriptEvent',
			ScriptEvents::POST_AUTOLOAD_DUMP  => 'handleScriptEvent',
			ScriptEvents::PRE_PACKAGE_INSTALL => 'checkContaoPackage',
			PluginEvents::PRE_FILE_DOWNLOAD   => 'handlePreDownload',
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
		switch ($this->getContaoVersion()) {
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

		throw new RuntimeException('Invalid version: ' . $version . '.' . $build);
	}

	/**
	 * Inject the currently installed contao/core as metapackage.
	 *
	 * @return void
	 */
	public function injectContaoCore()
	{
		$root              = $this->getContaoRoot($this->composer->getPackage());
		$repositoryManager = $this->composer->getRepositoryManager();
		$localRepository   = $repositoryManager->getLocalRepository();

		$versionParser = new VersionParser();
		$prettyVersion = $this->prepareContaoVersion($this->getContaoVersion(), $this->getContaoBuild());
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

		$contaoVersion = $this->getContaoVersion() . '.' . $this->getContaoBuild();
		$contaoCore    = new CompletePackage('contao/core', $version, $prettyVersion);
		$contaoCore->setType('metapackage');
		$contaoCore->setDistType('zip');
		$contaoCore->setDistUrl('https://github.com/contao/core/archive/' . $contaoVersion . '.zip');
		$contaoCore->setDistReference($contaoVersion);
		$contaoCore->setDistSha1Checksum($contaoVersion);
		$contaoCore->setInstallationSource('dist');
		$contaoCore->setAutoload(array());

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
			// load here to make sure the version information is present.
			$this->getContaoRoot($this->composer->getPackage());

			$versionParser = new VersionParser();
			$prettyVersion = $this->prepareContaoVersion($this->getContaoVersion(), $this->getContaoBuild());
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
		$contaoRoot             = $this->getContaoRoot($this->composer->getPackage());
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
				$root    = $this->getContaoRoot($package);

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
		$root = $this->getContaoRoot($this->composer->getPackage());

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
	 * Check if a contao package should be installed,
	 * prevent from installing, if contao/core is installed in the parent directory.
	 *
	 * @var PackageEvent $event
	 */
	public function checkContaoPackage(PackageEvent $event)
	{
		/** @var PackageInterface $package */
		$package = $event->getOperation()->getPackage();

		if ($package->getName() == 'contao/core') {
			try {
				$composer = $event->getComposer();
				$this->getContaoRoot($composer->getPackage());

				// contao is already installed in parent directory,
				// prevent installing contao/core in vendor!
				if (isset($this->contaoVersion)) {
					throw new DuplicateContaoException(
						'Warning: Contao core was about to get installed but has been found in project root, ' .
						'to recover from this problem please restart the operation'
					);
				}
			}
			// @codingStandardsIgnoreStart - Silently ignore the fact that the constants are not found.
			catch (ConstantsNotFoundException $e) {
				// gracefully ignore
			}
			// @codingStandardsIgnoreEnd

			$this->contaoRoot       = null;
			$this->contaoVersion    = null;
			$this->contaoBuild      = null;
			$this->contaoUploadPath = null;
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
	 *
	 * @throws RuntimeException If the current working directory can not be determined.
	 */
	public function getContaoRoot(RootPackageInterface $package)
	{
		if (!isset($this->contaoRoot)) {
			$cwd = getcwd();

			if (!$cwd) {
				throw new RuntimeException('Could not determine current working directory.');
			}

			$root  = dirname($cwd);
			$extra = $package->getExtra();

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

			$this->contaoRoot = $root;
		}

		$systemDir = $this->contaoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
		$configDir = $systemDir . 'config' . DIRECTORY_SEPARATOR;

		$this->detectVersion($systemDir, $configDir, $this->contaoRoot);
		$this->loadConfig($configDir);

		return $this->contaoRoot;
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
	 * @throws RuntimeException
	 */
	protected function detectVersion($systemDir, $configDir, $root)
	{
		if (isset($this->contaoVersion) && isset($this->contaoBuild)) {
			return;
		}

		foreach (array(
			$configDir . 'constants.php',
			$systemDir . 'constants.php'
		) as $checkConstants) {
			if (file_exists($checkConstants)) {
				$constantsFile = $checkConstants;
				break;
			}
		}

		if (!isset($constantsFile)) {
			throw new ConstantsNotFoundException('Could not find constants.php in ' . $root);
		}

		$contents = file_get_contents($constantsFile);

		if (preg_match('#define\(\'VERSION\', \'([^\']+)\'\);#', $contents, $match)) {
			$this->contaoVersion = $match[1];
		}

		if (preg_match('#define\(\'BUILD\', \'([^\']+)\'\);#', $contents, $match)) {
			$this->contaoBuild = $match[1];
		}
	}

	public function getContaoVersion()
	{
		if (!isset($this->contaoVersion)) {
			throw new RuntimeException(
				'Contao version is not set. Has getContaoRoot() been called before?'
			);
		}

		return $this->contaoVersion;
	}

	public function getContaoBuild()
	{
		if (!isset($this->contaoBuild)) {
			throw new RuntimeException(
				'Contao build is not set. Has getContaoRoot() been called before?'
			);
		}

		return $this->contaoBuild;
	}

	public function getContaoUploadPath()
	{
		if (!isset($this->contaoUploadPath)) {
			throw new RuntimeException(
				'Contao upload path is not set. Has getContaoRoot() been called before?'
			);
		}

		return $this->contaoUploadPath;
	}

	/**
	 * Retrieve a config value from the given config file.
	 *
	 * This is a very rudimentary parser for the Contao config files.
	 * It does only support on line assignments and primitive types but this is enough for this
	 * plugin to retrieve the data it needs to retrieve.
	 *
	 * @param $configFile
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	protected function extractKeyFromConfigFile($configFile, $key)
	{
		if (!file_exists($configFile)) {
			return null;
		}

		$value  = null;
		$lines  = file($configFile);
		$search = '$GLOBALS[\'TL_CONFIG\'][\'' . $key . '\']';
		$length = strlen($search);
		foreach ($lines as $line) {
			$tline = trim($line);
			if (strncmp($search, $tline, $length) === 0) {
				$parts = explode('=', $tline, 2);
				$tline = trim($parts[1]);

				if ($tline === 'true;') {
					$value = true;
				}
				else if ($tline === 'false;') {
					$value = false;
				}
				else if ($tline === 'null;') {
					$value = null;
				}
				else if ($tline === 'array();') {
					$value = array();
				}
				else if ($tline[0] === '\'') {
					$value = substr($tline, 1, -2);
				}
				else {
					$value = substr($tline, 0, -1);
				}
			}
		}

		return $value;
	}

	/**
	 * Retrieve a config value from the given config path
	 *
	 * @param string $configPath
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	protected function extractKeyFromConfigPath($configPath, $key)
	{
		// load default config
		if (version_compare($this->getContaoVersion(), '3', '>=')) {
			$value = $this->extractKeyFromConfigFile(
				$configPath . 'default.php',
				$key
			);
		}
		else {
			$value = $this->extractKeyFromConfigFile(
				$configPath . 'config.php',
				$key
			);
		}

		if ($override = $this->extractKeyFromConfigFile(
			$configPath . 'localconfig.php',
			$key
		)) {
			$value = $override;
		}

		return $value;
	}

	/**
	 * Load the configuration.
	 *
	 * @param $configDir
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
	 */
	protected function loadConfig($configDir)
	{
		if (!isset($this->contaoUploadPath)) {
			$this->contaoUploadPath = $this->extractKeyFromConfigPath($configDir, 'uploadPath');
		}
	}
}
