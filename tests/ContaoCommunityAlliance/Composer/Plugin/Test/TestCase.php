<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Util\Filesystem;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    protected function ensureDirectoryExistsAndClear($directory)
    {
        // Necessary to really clear the cache on travis-ci.
        // Otherwise the info is outdated causing is_dir() to report true.
        // This happened to confuse the tests of the symlink installer in all php versions from 5.3, 5.4, 5.5 but
        // only on travis-ci. The development machines of @tristanlins and @discordier ran all tests just fine
        // making this a PITA to debug. Thanks to the fine guys over at travis-ci who provided me with a debug machine,
        // I got this issue sorted out. For discussion, refer to:
        // https://github.com/contao-community-alliance/composer-plugin/issues/7
        clearstatcache(true);

        $fs = new Filesystem();
        if (!$fs->removeDirectory($directory)) {
            throw new \RuntimeException('Deletion of '.$directory.' was not successful.');
        }

        $fs->ensureDirectoryExists($directory);
    }
}
