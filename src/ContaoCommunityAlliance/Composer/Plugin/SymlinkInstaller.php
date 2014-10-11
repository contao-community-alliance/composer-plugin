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
                }
                else if (!is_link($linkReal)) {
                    throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
                }
            }

            $linkTarget = $this->calculateLinkTarget($targetReal, $linkReal);

            $links[] = $linkRel;

            if (is_link($linkReal)) {
                // link target has changed
                if (readlink($linkReal) != $linkTarget) {
                    unlink($linkReal);
                }
                // link exists and have the correct target
                else {
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

    protected function calculateLinkTarget($targetReal, $linkReal)
    {
        $targetParts = explode(DIRECTORY_SEPARATOR, $targetReal);
        $targetParts = array_filter($targetParts);
        $targetParts = array_values($targetParts);

        $linkParts = explode(DIRECTORY_SEPARATOR, $linkReal);
        $linkParts = array_filter($linkParts);
        $linkParts = array_values($linkParts);

        // calculate a relative link target
        $linkTargetParts = array();

        while (count($targetParts) && count($linkParts) && $targetParts[0] == $linkParts[0]) {
            array_shift($targetParts);
            array_shift($linkParts);
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
