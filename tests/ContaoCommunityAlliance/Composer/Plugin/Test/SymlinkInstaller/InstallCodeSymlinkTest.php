<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test\SymlinkInstaller;

use Composer\Config;
use ContaoCommunityAlliance\Composer\Plugin\AbstractInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Test\InstallCodeBase;

class InstallCodeSymlinkTest
    extends InstallCodeBase
{
    /**
     * @return AbstractInstaller
     */
    protected function mockInstaller()
    {
        $installer = $this
            ->getMock('\ContaoCommunityAlliance\Composer\Plugin\SymlinkInstaller', array('getUploadPath'), array($this->io, $this->composer, $this->plugin));

        $installer
            ->expects($this->any())
            ->method('getUploadPath')
            ->will($this->returnValue($this->uploadDir));

        return $installer;
    }
}
