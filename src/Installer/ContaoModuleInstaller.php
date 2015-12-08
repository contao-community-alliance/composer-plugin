<?php

/**
 * Contao Composer Plugin
 *
 * Copyright (C) 2013-2015 Contao Community Alliance
 *
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

/**
 * ContaoModuleInstaller installs Composer packages of type "contao-module".
 * These are the Contao modules available on Packagist.
 *
 * @author Andreas Schempp <https://github.com/aschempp>
 */
class ContaoModuleInstaller extends AbstractModuleInstaller
{
    /**
     * Constructor.
     *
     * @param RunonceManager  $runonceManager
     *
     * {@inheritdoc}
     */
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $io,
        Composer $composer,
        $type = 'contao-module',
        Filesystem $filesystem = null
    ) {
        parent::__construct($runonceManager, $io, $composer, $type, $filesystem);

        $this->runonceManager = $runonceManager;
    }

    /**
     * Gets installation files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getSources(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'sources') ?: [];
    }

    /**
     * Gets user files (TL_FILES) from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getUserFiles(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'userfiles') ?: [];
    }

    /**
     * Gets runonce files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getRunonces(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'runonce') ?: [];
    }

    /**
     * Retrieves a value from the package extra "contao" section.
     *
     * @param PackageInterface $package
     * @param string           $key
     *
     * @return mixed|null
     */
    private function getContaoExtra(PackageInterface $package, $key)
    {
        $extras = $package->getExtra();

        if (!isset($extras['contao']) || !isset($extras['contao'][$key])) {
            return null;
        }

        return $extras['contao'][$key];
    }
}
