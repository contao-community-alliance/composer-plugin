<?php

/**
 * This file is part of contao-community-alliance/composer-plugin.
 *
 * (c) 2013 Contao Community Alliance
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/composer-plugin
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Oliver Hoff <oliver@hofff.com>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;
use RuntimeException;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
        if ($config->get('preferred-install') === 'dist') {
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
            PluginEvents::COMMAND              => 'handleCommand',
            ScriptEvents::POST_UPDATE_CMD      => 'handlePostUpdateCmd',
            ScriptEvents::POST_AUTOLOAD_DUMP   => 'handlePostAutoloadDump',
            PackageEvents::PRE_PACKAGE_UPDATE  => 'checkContaoPackage',
            PackageEvents::PRE_PACKAGE_INSTALL => 'checkContaoPackage',
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
     * Inject the contao/*-bundle versions into the Contao package.
     *
     * @param WritableRepositoryInterface $repository    The repository where to add the packages.
     *
     * @param string                      $version       The version to use.
     *
     * @param string                      $prettyVersion The version to use.
     *
     * @return void
     */
    protected function injectContaoBundles(WritableRepositoryInterface $repository, $version, $prettyVersion)
    {
        foreach (Environment::$bundleNames as $bundleName) {
            if ($remove = $repository->findPackage($bundleName, '*')) {
                if ($this->isNotMetaPackageOrHasSameVersion($remove, $version)) {
                    // stop if the package is required somehow and must not be injected or if the virtual package is
                    // already injected.
                    continue;
                }
                // Otherwise remove the package.
                $repository->removePackage($remove);
            }

            $package = new CompletePackage($bundleName, $version, $prettyVersion);
            $package->setType('metapackage');
            $repository->addPackage($package);
        }
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
     *
     * @throws ConstantsNotFoundException When the root path could not be determined.
     */
    public function injectContaoCore()
    {
        $roots = Environment::findContaoRoots($this->composer->getPackage());
        if (0 === count($roots)) {
            throw new ConstantsNotFoundException('Could not find contao root path and therefore no constants.php');
        }

        // Duplicate installation, remove from vendor folder
        $removeVendor      = (count($roots) > 1 && isset($roots['vendor']));
        $root              = $this->getContaoRoot($this->composer->getPackage());
        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepository   = $repositoryManager->getLocalRepository();
        $versionParser     = new VersionParser();
        $prettyVersion     = $this->prepareContaoVersion($this->getContaoVersion(), $this->getContaoBuild());
        $version           = $versionParser->normalize($prettyVersion);

        // @codingStandardsIgnoreStart
        // Sadly we can not add the bundles as provided packages, as the Pool cleans them up.
        // See also: https://github.com/composer/composer/blob/2d19cf/src/Composer/DependencyResolver/Pool.php#L174
        // The skipping in there ignores any provided packages, even from already installed ones, and therefore makes
        // this approach impossible.
        // We therefore register them all as meta packages in the local repository and require them in the same version
        // below then.
        // @codingStandardsIgnoreEnd
        $this->injectContaoBundles($localRepository, $version, $prettyVersion);

        /** @var PackageInterface $localPackage */
        foreach ($localRepository->getPackages() as $localPackage) {
            if ($localPackage->getName() === 'contao/core') {
                if ($removeVendor) {
                    $this->composer->getInstallationManager()->uninstall(
                        $localRepository,
                        new UninstallOperation($localPackage)
                    );
                } elseif ($this->isNotMetaPackageOrHasSameVersion($localPackage, $version)) {
                    // stop if the contao package is required somehow and must not be injected or
                    // if the virtual contao package is already injected
                    return;
                }
                // Remove package otherwise.
                $localRepository->removePackage($localPackage);
                break;
            }
        }

        $contaoCore = new CompletePackage('contao/core', $version, $prettyVersion);
        $contaoCore->setType('metapackage');

        $this->injectSwiftMailer($root, $contaoCore);

        $clientLink = new Link(
            'contao/core',
            'contao-community-alliance/composer-client',
            $this->createEmptyConstraint('~0.14'),
            'requires',
            '~0.14'
        );

        $requires = array('contao-community-alliance/composer-client' => $clientLink);

        // Add the bundles now.
        foreach (Environment::$bundleNames as $bundleName) {
            if ($package = $localRepository->findPackage($bundleName, '*')) {
                $requires[$bundleName] =
                    new Link(
                        'contao/core',
                        $bundleName,
                        $this->createEmptyConstraint($package->getVersion()),
                        'requires',
                        $package->getVersion()
                    );
            }
        }

        $contaoCore->setRequires($requires);

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

        RunonceManager::createRunonce($this->inputOutput, $root);
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
        $operation = $event->getOperation();

        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            return;
        }

        if (($package->getName() === 'contao/core') || in_array($package->getName(), Environment::$bundleNames)) {
            try {
                $composer = $event->getComposer();
                $contao   = $this->getContaoRoot($composer->getPackage());
                $vendor   = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

                // Contao is already installed in parent directory, prevent installing in vendor!
                if ($contao && $vendor !== substr($contao, 0, strlen($vendor))) {
                    throw new DuplicateContaoException(
                        sprintf(
                            'Warning: Contao core %s was about to get installed but %s.%s has been found in ' .
                            'project root, to recover from this problem please restart the operation',
                            $package->getFullPrettyVersion(),
                            $this->contaoVersion,
                            $this->contaoBuild
                        )
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

    /**
     * Detect the contao installation root, version and configuration and set the TL_ROOT constant if not already exist.
     *
     * Existing values could originate from previous run or when run within contao.
     *
     * @param RootPackageInterface $package The package being processed.
     *
     * @return string|null
     *
     * @throws RuntimeException If the current working directory can not be determined.
     */
    public function getContaoRoot(RootPackageInterface $package)
    {
        if (!isset($this->contaoRoot)) {
            $roots = array_values(Environment::findContaoRoots($package));
            if (!isset($roots[0])) {
                return $this->contaoRoot = null;
            }
            $this->contaoRoot = $roots[0];
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

    /**
     * Create an empty constraint instance and set pretty string.
     *
     * @param string $prettyString The pretty string for the constraint.
     *
     * @return EmptyConstraint|\Composer\Package\LinkConstraint\EmptyConstraint
     * @see    https://github.com/contao-community-alliance/composer-plugin/issues/44
     * @see    https://github.com/composer/semver/issues/17
     */
    private function createEmptyConstraint($prettyString)
    {
        if (!class_exists('Composer\Semver\Constraint\EmptyConstraint')) {
            $constraint = new \Composer\Package\LinkConstraint\EmptyConstraint();
        } else {
            $constraint = new EmptyConstraint();
        }
        $constraint->setPrettyString($prettyString);

        return $constraint;
    }

    /**
     * Check if the passed package is not a meta package or has the same version.
     *
     * @param PackageInterface $package The package to check.
     *
     * @param string           $version The version to match against.
     *
     * @return bool
     */
    private function isNotMetaPackageOrHasSameVersion(PackageInterface $package, $version)
    {
        return ('metapackage' !== $package->getType()) || ($package->getVersion() == $version);
    }
}
