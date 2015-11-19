<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
