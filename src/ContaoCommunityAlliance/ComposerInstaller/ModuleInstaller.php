<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class ModuleInstaller extends LibraryInstaller
{
    public function getInstallPath(PackageInterface $package)
    {
        $extra = $package->getExtra();

        if(!array_key_exists('contao', $extra))
        {
            throw new \ErrorException("A contao-module needs the contao declaration within the extra block!");
        }

        $contao = $extra['contao'];

        if(!array_key_exists('target', $contao))
        {
            throw new \ErrorException("Please add a target key to the contao section, _composer for example (folder name in contao)!");
        }

        return '../system/modules/' . $contao['target'];
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'contao-module' === $packageType;
    }
}