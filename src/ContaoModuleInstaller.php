<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

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
