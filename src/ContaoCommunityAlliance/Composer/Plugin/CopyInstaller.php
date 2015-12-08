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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Martin Auswöger <martin@auswoeger.com>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Package\PackageInterface;

/**
 * Module installer that use copies to install the extensions into the contao file hierarchy.
 */
class CopyInstaller extends AbstractInstaller
{
    /**
     * {@inheritdoc}
     */
    protected function updateSources($map, PackageInterface $package)
    {
        $deleteCount = 0;
        $copyCount   = 0;

        $root        = $this->plugin->getContaoRoot($this->composer->getPackage());
        $installPath = $this->getInstallPath($package);
        $sources     = $this->getSourcesSpec($package);

        // remove symlinks
        $this->removeAllSymlinks($map, $root, $deleteCount);

        // update copies
        $copies = $this->updateAllCopies($sources, $root, $installPath, $copyCount);

        // remove obsolete copies
        $this->removeObsoleteCopies($map, $copies, $root, $deleteCount);

        if ($deleteCount) {
            $this->write(
                sprintf(
                    '  - removed <info>%d</info> files',
                    $deleteCount
                )
            );
        }

        if ($copyCount) {
            $this->write(
                sprintf(
                    '  - installed <info>%d</info> files',
                    $copyCount
                )
            );
        }
    }

    /**
     * Remove all symlinks.
     *
     * @param array  $map         The mapping.
     *
     * @param string $root        The root dir.
     *
     * @param int    $deleteCount The amount of items deleted.
     *
     * @return void
     */
    protected function removeAllSymlinks($map, $root, &$deleteCount)
    {
        foreach (array_values($map['links']) as $link) {
            $this->writeVerbose(
                sprintf(
                    '  - rm link <info>%s</info>',
                    $link
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $link);
            $deleteCount++;
        }
    }

    /**
     * Update the files in the destination dir.
     *
     * @param array  $sources     The file map to be copied.
     *
     * @param string $root        The root directory.
     *
     * @param string $installPath The destination path.
     *
     * @param int    $copyCount   The amount of items copied.
     *
     * @return array
     */
    protected function updateAllCopies($sources, $root, $installPath, &$copyCount)
    {
        $copies = array();
        foreach ($sources as $source => $target) {
            if (is_dir($installPath . DIRECTORY_SEPARATOR . $source)) {
                $files    = array();
                $iterator = new \RecursiveDirectoryIterator(
                    $installPath . DIRECTORY_SEPARATOR . $source,
                    (\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
                );
                $iterator = new \RecursiveIteratorIterator(
                    $iterator
                );
                foreach ($iterator as $sourceFile) {
                    $unPrefixedPath     = self::unprefixPath(
                        $installPath . DIRECTORY_SEPARATOR . ($source ? $source . DIRECTORY_SEPARATOR : ''),
                        $sourceFile->getRealPath()
                    );
                    $targetPath         = $target . DIRECTORY_SEPARATOR . $unPrefixedPath;
                    $files[$targetPath] = $sourceFile;
                }
            } else {
                $files = array($target => new \SplFileInfo($installPath . DIRECTORY_SEPARATOR . $source));
            }

            /** @var \SplFileInfo $sourceFile */
            foreach ($files as $targetPath => $sourceFile) {
                if ($sourceFile->isLink()) {
                    $this->write(
                        sprintf(
                            '<warning>Warning: %s is a symlink and will not get copied.</warning>',
                            self::unprefixPath($root . DIRECTORY_SEPARATOR, $sourceFile->getPathname())
                        )
                    );
                    continue;
                }

                $this->writeVerbose(
                    sprintf(
                        '  - cp <info>%s</info>',
                        $targetPath
                    )
                );

                $this->filesystem->ensureDirectoryExists(dirname($root . DIRECTORY_SEPARATOR . $targetPath));
                copy($sourceFile->getRealPath(), $root . DIRECTORY_SEPARATOR . $targetPath);
                $copyCount++;
                $copies[] = $targetPath;
            }
        }

        return $copies;
    }

    /**
     * Remove all obsolete files.
     *
     * @param array  $map         The file map.
     *
     * @param array  $copies      The files that have been copied.
     *
     * @param string $root        The root directory.
     *
     * @param int    $deleteCount The amount of files deleted.
     *
     * @return void
     */
    protected function removeObsoleteCopies($map, $copies, $root, &$deleteCount)
    {
        // fix inconsistence directory separator
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $map['copies'] = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $map['copies']);
            $copies        = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $copies);
        }
        
        $obsoleteCopies = array_diff($map['copies'], $copies);
        foreach ($obsoleteCopies as $obsoleteCopy) {
            $this->writeVerbose(
                sprintf(
                    '  - rm obsolete <info>%s</info>',
                    $obsoleteCopy
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsoleteCopy);
            $deleteCount++;
        }
    }
}
