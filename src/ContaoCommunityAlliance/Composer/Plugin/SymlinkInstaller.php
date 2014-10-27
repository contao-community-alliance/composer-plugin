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

use Composer\Package\PackageInterface;

/**
 * Module installer that use symlinks to install the extensions into the contao file hierarchy.
 */
class SymlinkInstaller extends AbstractInstaller
{
    /**
     * {@inheritdoc}
     */
    protected function updateSources($map, PackageInterface $package)
    {
        $root        = $this->plugin->getContaoRoot($this->composer->getPackage());
        $installPath = $this->getInstallPath($package);
        $sources     = $this->getSourcesSpec($package);

        $deleteCount = 0;
        $linkCount   = 0;

        // remove copies
        $this->removeAllCopies($map, $root, $deleteCount);

        // update symlinks
        $links = $this->updateAllSymlinks($sources, $root, $installPath, $linkCount);

        // remove obsolete links
        $this->removeObsoleteSymlinks($map, $links, $root, $deleteCount);

        if ($deleteCount) {
            $this->write(
                sprintf(
                    '  - removed <info>%d</info> files',
                    $deleteCount
                )
            );
        }

        if ($linkCount) {
            $this->write(
                sprintf(
                    '  - created <info>%d</info> links',
                    $linkCount
                )
            );
        }
    }

    /**
     * Remove all copies.
     *
     * @param array  $map         The mapping.
     *
     * @param string $root        The root dir.
     *
     * @param int    $deleteCount The amount of items deleted.
     *
     * @return void
     */
    protected function removeAllCopies($map, $root, &$deleteCount)
    {
        foreach ($map['copies'] as $target) {
            $this->writeVerbose(
                sprintf(
                    '  - rm copy <info>%s</info>',
                    $target
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $target);
            $deleteCount++;
            $this->removeEmptyDirectories(dirname($root . DIRECTORY_SEPARATOR . $target));
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
     * @param int    $linkCount   The amount of items linked.
     *
     * @return array
     *
     * @throws \Exception When a symlink could not be created.
     */
    protected function updateAllSymlinks($sources, $root, $installPath, &$linkCount)
    {
        $links = array();
        foreach ($sources as $target => $link) {
            $targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
            $linkReal   = $root . DIRECTORY_SEPARATOR . $link;
            $linkRel    = self::unprefixPath($root . DIRECTORY_SEPARATOR, $linkReal);

            if (file_exists($linkReal)) {
                // an empty directory was left...
                if (is_dir($linkReal) && count(scandir($linkReal)) == 2) {
                    rmdir($linkReal);
                } elseif (!is_link($linkReal)) {
                    throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
                }
            }

            $linkTarget = $this->calculateLinkTarget($targetReal, $linkReal);

            $links[] = $linkRel;

            if (is_link($linkReal)) {
                // link target has changed
                if (readlink($linkReal) != $linkTarget) {
                    unlink($linkReal);
                } else {
                    // link exists and has the correct target.
                    continue;
                }
            }

            $this->writeVerbose(
                sprintf(
                    '  - symlink <info>%s</info>',
                    $linkRel
                )
            );

            $linkParent = dirname($linkReal);
            if (!is_dir($linkParent)) {
                mkdir($linkParent, 0777, true);
            }

            symlink($linkTarget, $linkReal);
            $linkCount++;
        }
        return $links;
    }

    /**
     * Calculate the correct link target.
     *
     * @param string $targetReal The target path.
     *
     * @param string $linkReal   The destination path.
     *
     * @return string
     */
    protected function calculateLinkTarget($targetReal, $linkReal)
    {
        // return absolute path for Windows platforms
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            return $targetReal;
        }
        
        $targetParts = explode(DIRECTORY_SEPARATOR, $targetReal);
        $targetParts = array_filter($targetParts);
        $targetParts = array_values($targetParts);

        $linkParts = explode(DIRECTORY_SEPARATOR, $linkReal);
        $linkParts = array_filter($linkParts);
        $linkParts = array_values($linkParts);

        // calculate a relative link target
        $linkTargetParts = array();

        $targetPartsCount = count($targetParts);
        $linkPartsCount   = count($linkParts);
        while ($targetPartsCount && $linkPartsCount && $targetParts[0] == $linkParts[0]) {
            array_shift($targetParts);
            array_shift($linkParts);
            $targetPartsCount = count($targetParts);
            $linkPartsCount   = count($linkParts);
        }

        $count = count($linkParts);
        // start on $i=1 -> skip the link name itself
        for ($i = 1; $i < $count; $i++) {
            $linkTargetParts[] = '..';
        }

        $linkTargetParts = array_merge(
            $linkTargetParts,
            $targetParts
        );

        return implode(DIRECTORY_SEPARATOR, $linkTargetParts);
    }

    /**
     * Remove all obsolete symlinks.
     *
     * @param array  $map         The file map.
     *
     * @param array  $links       The files that have been copied.
     *
     * @param string $root        The root directory.
     *
     * @param int    $deleteCount The amount of files deleted.
     *
     * @return void
     */
    protected function removeObsoleteSymlinks($map, $links, $root, &$deleteCount)
    {
        $obsoleteLinks = array_diff(array_keys($map['links']), $links);
        foreach ($obsoleteLinks as $obsoleteLink) {
            $this->writeVerbose(
                sprintf(
                    '  - rm symlink <info>%s</info>',
                    $obsoleteLink
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsoleteLink);
            $deleteCount++;
        }
    }
}
