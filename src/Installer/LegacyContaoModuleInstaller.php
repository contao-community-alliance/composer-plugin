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
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
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
 */
class LegacyContaoModuleInstaller extends AbstractModuleInstaller
{
    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager The run once manager to use.
     *
     * {@inheritdoc}
     */
    // @codingStandardsIgnoreStart - Overriding this method is not useless, we add a parameter default here.
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $inputOutput,
        Composer $composer,
        $type = 'legacy-contao-module',
        Filesystem $filesystem = null
    // @codingStandardsIgnoreEnd
    ) {
        parent::__construct($runonceManager, $inputOutput, $composer, $type, $filesystem);
    }

    /**
     * {@inheritDoc}
     */
    protected function getSources(PackageInterface $package)
    {
        return $this->getFileMap($package, 'TL_ROOT');
    }

    /**
     * {@inheritDoc}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function getRunonces(PackageInterface $package)
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUserFiles(PackageInterface $package)
    {
        return $this->getFileMap($package, 'TL_FILES');
    }

    /**
     * Generate a file map from the passed directory.
     *
     * @param PackageInterface $package   The package being processed.
     *
     * @param string           $directory The name of the directory to extract.
     *
     * @return array
     */
    private function getFileMap(PackageInterface $package, $directory)
    {
        $files = [];
        $root  = $this->getInstallPath($package);

        if (!file_exists($root . '/' . $directory)) {
            return [];
        }

        $iterator = new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isFile()) {
                $path = str_replace($root . '/' . $directory . '/', '', $file->getPathname());

                $files[$directory . '/' . $path] = $path;
            }
        }

        return $files;
    }
}
