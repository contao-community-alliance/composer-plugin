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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * LegacyContaoModuleInstaller installs Composer packages of type "legacy-contao-module".
 * These are provided by the Packagist gateway to the old extension repository.
 *
 * @author Andreas Schempp <https://github.com/aschempp>
 */
class LegacyContaoModuleInstaller extends AbstractModuleInstaller
{
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $io,
        Composer $composer,
        $type = 'legacy-contao-module',
        Filesystem $filesystem = null
    ) {
        parent::__construct($runonceManager, $io, $composer, $type, $filesystem);
    }


    protected function getSources(PackageInterface $package)
    {
        return $this->getFileMap($package, 'TL_ROOT');
    }

    protected function getRunonces(PackageInterface $package)
    {
        return [];
    }

    protected function getUserFiles(PackageInterface $package)
    {
        return $this->getFileMap($package, 'TL_FILES');
    }

    private function getFileMap(PackageInterface $package, $directory)
    {
        $files = [];
        $root  = $this->getInstallPath($package);
        $it    = new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri    = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $file */
        foreach ($ri as $file) {
            if ($file->isFile()) {
                $path = str_replace($root . '/', '', $file->getPath());

                $files[$directory . '/' . $path] = $path;
            }
        }

        return $files;
    }
}
