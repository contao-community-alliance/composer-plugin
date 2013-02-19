<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class ModuleInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'contao-module' === $packageType;
    }
}