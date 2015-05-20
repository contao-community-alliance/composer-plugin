<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Environment;

use Composer\Composer;
use Composer\Package\RootPackageInterface;

class ContaoEnvironmentFactory
{

    /**
     * @param Composer $composer
     *
     * @return ContaoEnvironmentInterface
     */
    public function create(Composer $composer)
    {
        $rootDir = $this->findRoot($composer->getPackage());

        if ($this->isContao3($rootDir)) {
            return new Contao3Environment($rootDir);
        }

        if ($this->isContao4($rootDir)) {
            return new Contao4Environment($rootDir, $composer);
        }

        throw new UnknownEnvironmentException('Contao installation was not found.');
    }

    private function isContao3($rootDir)
    {
        return is_file($rootDir . '/system/config/constants.php');
    }

    private function isContao4($rootDir)
    {
        return is_file($rootDir . '/app/console');
    }

    private function findRoot(RootPackageInterface $package)
    {
        $cwd = getcwd();

        if (!$cwd) {
            throw new \RuntimeException('Could not determine current working directory.');
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

        $root = realpath($root);

        if (null === $root) {
            throw new \RuntimeException('Could not determine Contao root directory.');
        }

        return rtrim($root, '/');
    }
}
