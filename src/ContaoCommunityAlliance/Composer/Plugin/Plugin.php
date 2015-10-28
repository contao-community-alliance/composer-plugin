<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @author  Oliver Hoff <oliver@hofff.com>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
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
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;
use RuntimeException;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The composer instance.
     *
     * @var Composer
     */
    protected $composer;

    /**
     * The input output interface.
     *
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
     * The Contao version.
     *
     * @var string
     */
    protected $contaoVersion;

    /**
     * The Contao build.
     *
     * @var string
     */
    protected $contaoBuild;

    /**
     * The Contao upload path.
     *
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
        } else {
            $installer = new SymlinkInstaller($inputOutput, $composer, $this);
        }
        $installationManager->addInstaller($installer);

        // We must not inject core etc. when the root package itself is being installed via this plugin.
        if (!$installer->supports($composer->getPackage()->getType())
            && $composer->getPackage()->getPrettyName() !== 'contao/contao') {
            try {
                $this->injectContaoCore();
                $this->injectRequires();
            } catch (ConstantsNotFoundException $e) {
                // No op.
            }
        }

        class_exists('ContaoCommunityAlliance\Composer\Plugin\Housekeeper');
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            PluginEvents::COMMAND             => 'handleCommand',
            ScriptEvents::POST_UPDATE_CMD     => 'handlePostUpdateCmd',
            ScriptEvents::POST_AUTOLOAD_DUMP  => 'handlePostAutoloadDump',
            ScriptEvents::PRE_PACKAGE_INSTALL => 'checkContaoPackage',
            PluginEvents::PRE_FILE_DOWNLOAD   => 'handlePreDownload',
        );
    }

    /**
     * Inject the swiftMailer version into the Contao package.
     *
     * @param string          $contaoRoot The Contao root dir.
     *
     * @param CompletePackage $package    The package being processed.
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

            $swiftConstraint = $this->createConstraint('==', $swiftVersion);
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

    /**
     * Prepare a Contao version to be compatible with composer.
     *
     * @param string $version The version string.
     *
     * @param string $build   The version build portion.
     *
     * @return string
     *
     * @throws RuntimeException When an invalid version is encountered.
     */
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
     * Inject the currently installed contao/core as meta package.
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
                } elseif ($localPackage->getVersion() == $version) {
                    // stop if the virtual contao package is already injected
                    return;
                } else {
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

        if (!class_exists('Composer\Semver\Constraint\EmptyConstraint')) {
            $clientConstraint = new \Composer\Package\LinkConstraint\EmptyConstraint();
        } else {
            $clientConstraint = new EmptyConstraint();
        }
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
            $version       = $versionParser->normalize($prettyVersion);

            $constraint = $this->createConstraint('==', $version);
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
     * Handle command events.
     *
     * @param CommandEvent $event The event being raised.
     *
     * @return void
     *
     * @throws \RuntimeException When the artifact directory could not be created.
     */
    public function handleCommand(CommandEvent $event)
    {
        switch ($event->getCommandName()) {
            case 'update':
                // ensure the artifact repository exists
                $path = $this->composer->getConfig()->get('home') . DIRECTORY_SEPARATOR . 'packages';
                // @codingStandardsIgnoreStart - silencing the error is ok here.
                if (!is_dir($path) && !@mkdir($path, 0777, true)) {
                    throw new \RuntimeException(
                        'could not create directory "' . $path . '" for artifact repository',
                        1
                    );
                }
                // @codingStandardsIgnoreEnd

                ConfigManipulator::run();
                break;

            default:
        }
    }

    /**
     * Handle post update events.
     *
     * @return void
     */
    public function handlePostUpdateCmd()
    {
        $package = $this->composer->getPackage();
        $root    = $this->getContaoRoot($package);

        $this->createRunonce($this->inputOutput, $root);
        Housekeeper::cleanCache($this->inputOutput, $root);
    }

    /**
     * Handle post dump autoload events.
     *
     * @return void
     */
    public function handlePostAutoloadDump()
    {
        Housekeeper::cleanLocalConfig(
            $this->inputOutput,
            $this->getContaoRoot($this->composer->getPackage())
        );
    }

    /**
     * Create the global runonce.php after updates has been installed.
     *
     * @param IOInterface $inputOutput The input output interface.
     *
     * @param string      $root        The contao installation root.
     *
     * @return void
     */
    public function createRunonce(IOInterface $inputOutput, $root)
    {
        RunonceManager::createRunonce($inputOutput, $root);
    }

    /**
     * Check if a contao package should be installed.
     *
     * This prevents from installing, if contao/core is installed in the parent directory.
     *
     * @param PackageEvent $event The event being raised.
     *
     * @return void
     *
     * @throws DuplicateContaoException When Contao would be installed within an existing Contao installation.
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
            } catch (ConstantsNotFoundException $e) {
                // Silently ignore the fact that the constants are not found.
            }

            $this->contaoRoot       = null;
            $this->contaoVersion    = null;
            $this->contaoBuild      = null;
            $this->contaoUploadPath = null;
        }
    }

    // @codingStandardsIgnoreStart
    /**
     * Handle pre download events.
     *
     * @param PreFileDownloadEvent $event The event being raised.
     *
     * @return void
     */
    public function handlePreDownload()
    {
        // TODO: handle the pre download event.
    }
    // @codingStandardsIgnoreEnd

    /**
     * Detect the contao installation root, version and configuration and set the TL_ROOT constant if not already exist.
     *
     * Existing values could originate from previous run or when run within contao.
     *
     * @param RootPackageInterface $package The package being processed.
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

            // Check if we have a Contao installation in the current working dir. See #15.
            if (is_dir($cwd . DIRECTORY_SEPARATOR . 'system')) {
                $root = $cwd;
            } else {
                // Fallback - We assume we are in TL_ROOT/composer.
                $root = dirname($cwd);
            }
            $extra = $package->getExtra();

            if (!empty($extra['contao']['root'])) {
                $root = $cwd . DIRECTORY_SEPARATOR . $extra['contao']['root'];
            } else {
                // test, do we have the core within vendor/contao/core.
                $vendorRoot = $cwd . DIRECTORY_SEPARATOR .
                    'vendor' . DIRECTORY_SEPARATOR .
                    'contao' . DIRECTORY_SEPARATOR .
                    'core';

                if (is_dir($vendorRoot)) {
                    $root = $vendorRoot;
                }
            }

            $this->contaoRoot = realpath($root);
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
     * @param string $systemDir The system directory.
     *
     * @param string $configDir The configuration directory.
     *
     * @param string $root      The root directory.
     *
     * @return void
     *
     * @throws ConstantsNotFoundException When the constants file could not be found.
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

    /**
     * Retrieve the Contao version.
     *
     * @return string
     *
     * @throws RuntimeException When getContaoRoot() has not been called prior.
     */
    public function getContaoVersion()
    {
        if (!isset($this->contaoVersion)) {
            throw new RuntimeException(
                'Contao version is not set. Has getContaoRoot() been called before?'
            );
        }

        return $this->contaoVersion;
    }

    /**
     * Retrieve the Contao build number.
     *
     * @return string
     *
     * @throws RuntimeException When getContaoRoot() has not been called prior.
     */
    public function getContaoBuild()
    {
        if (!isset($this->contaoBuild)) {
            throw new RuntimeException(
                'Contao build is not set. Has getContaoRoot() been called before?'
            );
        }

        return $this->contaoBuild;
    }

    /**
     * Retrieve the Contao upload path.
     *
     * @return string
     *
     * @throws RuntimeException When getContaoRoot() has not been called prior.
     */
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
     * @param string $configFile The filename.
     *
     * @param string $key        The config key to retrieve.
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
                } elseif ($tline === 'false;') {
                    $value = false;
                } elseif ($tline === 'null;') {
                    $value = null;
                } elseif ($tline === 'array();') {
                    $value = array();
                } elseif ($tline[0] === '\'') {
                    $value = substr($tline, 1, -2);
                } else {
                    $value = substr($tline, 0, -1);
                }
            }
        }

        return $value;
    }

    /**
     * Retrieve a config value from the given config path.
     *
     * @param string $configPath The path where the config files are located.
     *
     * @param string $key        The config key to retrieve.
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
        } else {
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
     * @param string $configDir The path where the config files are located.
     *
     * @return void
     */
    protected function loadConfig($configDir)
    {
        if (!isset($this->contaoUploadPath)) {
            $this->contaoUploadPath = $this->extractKeyFromConfigPath($configDir, 'uploadPath');
        }
    }

    /**
     * Create a constraint instance and set operator and version to compare a package with.
     *
     * @param string $operator A comparison operator.
     * @param string $version  A version to compare to.
     *
     * @return Constraint|\Composer\Package\LinkConstraint\VersionConstraint
     * @see    https://github.com/contao-community-alliance/composer-plugin/issues/44
     * @see    https://github.com/composer/semver/issues/17
     */
    private function createConstraint($operator, $version)
    {
        if (!class_exists('Composer\Semver\Constraint\Constraint')) {
            return new \Composer\Package\LinkConstraint\VersionConstraint($operator, $version);
        }

        return new Constraint($operator, $version);
    }
}
