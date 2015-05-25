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
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Script\PackageEvent;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;
use ContaoCommunityAlliance\Composer\Plugin\Dependency\ConfigManipulator;
use ContaoCommunityAlliance\Composer\Plugin\Environment\ContaoEnvironmentFactory;
use ContaoCommunityAlliance\Composer\Plugin\Environment\ContaoEnvironmentInterface;
use ContaoCommunityAlliance\Composer\Plugin\Environment\UnknownEnvironmentException;
use ContaoCommunityAlliance\Composer\Plugin\Environment\UnknownSwiftmailerException;
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

        $this->detectEnvironment();

        $installationManager = $composer->getInstallationManager();

        $config = $composer->getConfig();
        if ($config->get('preferred-install') == 'dist') {
            $installer = new CopyInstaller($inputOutput, $composer, $this->environment);
        } else {
            $installer = new SymlinkInstaller($inputOutput, $composer, $this->environment);
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
        class_exists('ContaoCommunityAlliance\Composer\Plugin\Installer\RunonceInstaller');
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
        );
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
        }
    }

    /**
     * Handle post update events.
     *
     * @return void
     */
    public function handlePostUpdateCmd()
    {
        // Does nothing if Contao is not yet installed
        if (!$this->detectEnvironment()) {
            return;
        }

        $root = $this->environment->getRoot();

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
        // Does nothing if Contao is not yet installed
        if (!$this->detectEnvironment()) {
            return;
        }

        Housekeeper::cleanLocalConfig(
            $this->inputOutput,
            $this->environment->getRoot()
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
        /** @var PackageInterface $package */
        $package = $event->getOperation()->getPackage();

        if ($package->getName() == 'contao/core' || in_array($package->getName(), static::$provides)) {
            // contao is already installed in parent directory,
            // prevent installing contao/core in vendor!
            if (null !== $this->environment) {
                throw new DuplicateContaoException(
                    sprintf(
                        'Warning: %s was about to get installed but has been found in project root, ' .
                        'to recover from this problem please restart the operation',
                        $package->getName()
                    )
                );
            }
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
    private function prepareContaoVersion($version, $build)
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
    private function injectContaoCore()
    {
        // Do not inject anything in Contao 4
        if (version_compare($this->environment->getVersion(), '4.0', '>=')) {
            return;
        }

        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepository   = $repositoryManager->getLocalRepository();

        $versionParser = new VersionParser();
        $prettyVersion = $this->prepareContaoVersion($this->environment->getVersion(), $this->environment->getBuild());
        $version       = $versionParser->normalize($prettyVersion);
        $contaoVersion = $this->environment->getVersion() . '.' . $this->environment->getBuild();

        /** @var PackageInterface $localPackage */
        foreach ($localRepository->getPackages() as $localPackage) {
            if ($localPackage->getName() == 'contao/core' || in_array($localPackage->getName(), static::$provides)) {
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

        $contaoCore = new CompletePackage('contao/core', $version, $prettyVersion);
        $contaoCore->setType('metapackage');
        $contaoCore->setDistType('zip');
        $contaoCore->setDistUrl('https://github.com/contao/core/archive/' . $contaoVersion . '.zip');
        $contaoCore->setDistReference($contaoVersion);
        $contaoCore->setDistSha1Checksum($contaoVersion);
        $contaoCore->setInstallationSource('dist');
        $contaoCore->setAutoload(array());

        try {
            $this->addProvides($contaoCore, 'swiftmailer/swiftmailer', $this->environment->getSwiftMailerVersion());
        } catch (UnknownSwiftmailerException $e) {
            // Probably a version already supporting SwiftMailer
        }

        foreach (static::$provides as $package) {
            $this->addProvides($contaoCore, $package, $contaoVersion);
        }

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


    private function addProvides(CompletePackage $package, $name, $version)
    {
        $constraint = new VersionConstraint('==', $version);
        $constraint->setPrettyString($version);

        $link = new Link(
            $package->getName(),
            $name,
            $constraint,
            'provides',
            $version
        );

        $provides = $package->getProvides();
        $provides[$name] = $link;

        $package->setProvides($provides);
    }

    /**
     * Inject contao/core as permanent requirement into the root package.
     *
     * @return void
     */
    private function injectRequires()
    {
        $package  = $this->composer->getPackage();
        $requires = $package->getRequires();

        if (!isset($requires['contao/core'])) {
            $versionParser = new VersionParser();
            $prettyVersion = $this->prepareContaoVersion($this->environment->getVersion(), $this->environment->getBuild());
            $version       = $versionParser->normalize($prettyVersion);

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

    private function detectEnvironment()
    {
        try {
            $factory = new ContaoEnvironmentFactory();
            $this->environment = $factory->create($this->composer);

            return true;

        } catch (UnknownEnvironmentException $e) {
            $this->environment = null;

            return false;
        }
    }
}
