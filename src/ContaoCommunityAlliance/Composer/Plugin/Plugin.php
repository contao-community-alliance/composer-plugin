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
use Composer\Script\ScriptEvents;
use Composer\Script\PackageEvent;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;
use ContaoCommunityAlliance\Composer\Plugin\Dependency\ConfigManipulator;
use ContaoCommunityAlliance\Composer\Plugin\Environment\ContaoEnvironmentFactory;
use ContaoCommunityAlliance\Composer\Plugin\Environment\ContaoEnvironmentInterface;
use ContaoCommunityAlliance\Composer\Plugin\Exception\ConstantsNotFoundException;
use ContaoCommunityAlliance\Composer\Plugin\Exception\DuplicateContaoException;
use ContaoCommunityAlliance\Composer\Plugin\Installer\CopyInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Installer\RunonceManager;
use ContaoCommunityAlliance\Composer\Plugin\Installer\SymlinkInstaller;
use RuntimeException;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    static $provides = array(
        'contao/core',
        'contao/calendar-bundle',
        'contao/comments-bundle',
        'contao/core-bundle',
        'contao/faq-bundle',
        'contao/listing-bundle',
        'contao/news-bundle',
        'contao/newsletter-bundle'
    );

    /**
     * @var ContaoEnvironmentInterface
     */
    private $environment;

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
        $root = $this->getContaoRoot($this->composer);

        // Do not inject anything in Contao 4
        if (version_compare($this->getContaoVersion(), '4.0', '>=')) {
            return;
        }

        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepository   = $repositoryManager->getLocalRepository();

        $versionParser = new VersionParser();
        $prettyVersion = $this->prepareContaoVersion($this->getContaoVersion(), $this->getContaoBuild());
        $version       = $versionParser->normalize($prettyVersion);
        $contaoVersion = $this->getContaoVersion() . '.' . $this->getContaoBuild();

        foreach (static::$provides as $packageName) {
            /** @var PackageInterface $localPackage */
            foreach ($localRepository->getPackages() as $localPackage) {
                if ($localPackage->getName() == $packageName) {
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

            $contaoCore = new CompletePackage($packageName, $version, $prettyVersion);
            $contaoCore->setType('metapackage');
            $contaoCore->setDistType('zip');
            $contaoCore->setDistUrl('https://github.com/contao/core/archive/' . $contaoVersion . '.zip');
            $contaoCore->setDistReference($contaoVersion);
            $contaoCore->setDistSha1Checksum($contaoVersion);
            $contaoCore->setInstallationSource('dist');
            $contaoCore->setAutoload(array());

            // Only run this once
            if ('contao/core' === $packageName) {
                $this->injectSwiftMailer($root, $contaoCore);
            }

            $clientConstraint = new EmptyConstraint();
            $clientConstraint->setPrettyString('*');
            $clientLink = new Link(
                $packageName,
                'contao-community-alliance/composer',
                $clientConstraint,
                'requires',
                '*'
            );
            $contaoCore->setRequires(array('contao-community-alliance/composer' => $clientLink));

            $localRepository->addPackage($contaoCore);
        }
    }

    /**
     * Inject the contao/core-bundle as permanent requirement into the root package.
     *
     * @return void
     */
    public function injectRequires()
    {
        $package  = $this->composer->getPackage();
        $requires = $package->getRequires();

        if (!isset($requires['contao/core-bundle'])) {
            // load here to make sure the version information is present.
            $this->getContaoRoot($this->composer);

            $versionParser = new VersionParser();
            $prettyVersion = $this->prepareContaoVersion($this->getContaoVersion(), $this->getContaoBuild());
            $version       = $versionParser->normalize($prettyVersion);

            $constraint = new VersionConstraint('==', $version);
            $constraint->setPrettyString($prettyVersion);
            $requires['contao/core-bundle'] = new Link(
                'contao/core-bundle',
                'contao/core-bundle',
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
        $root    = $this->getContaoRoot($this->composer);

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
            $this->getContaoRoot($this->composer)
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

        if ($package->getName() == 'contao/core-bundle') {
            try {
                $composer = $event->getComposer();
                $this->getContaoRoot($composer);

                // contao is already installed in parent directory,
                // prevent installing contao/core-bundle in vendor!
                if (isset($this->contaoVersion)) {
                    throw new DuplicateContaoException(
                        'Warning: Contao core was about to get installed but has been found in project root, ' .
                        'to recover from this problem please restart the operation'
                    );
                }
            } catch (ConstantsNotFoundException $e) {
                // Silently ignore the fact that the constants are not found.
            }

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
    public function getContaoRoot(Composer $composer)
    {
        if (null === $this->environment) {
            $factory = new ContaoEnvironmentFactory();
            $this->environment = $factory->create($composer);
        }

        return $this->environment->getRoot();
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
        if (null === $this->environment) {
            throw new RuntimeException(
                'Contao environment is not set. Has getContaoRoot() been called before?'
            );
        }

        // FIXME: why do we need that? see checkContaoPackage() version check
        $this->contaoVersion = $this->environment->getVersion();

        return $this->environment->getVersion();
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
        if (null === $this->environment) {
            throw new RuntimeException(
                'Contao environment is not set. Has getContaoRoot() been called before?'
            );
        }

        return $this->environment->getBuild();
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
        if (null === $this->environment) {
            throw new RuntimeException(
                'Contao environment is not set. Has getContaoRoot() been called before?'
            );
        }

        return $this->environment->getUploadPath();
    }
}
