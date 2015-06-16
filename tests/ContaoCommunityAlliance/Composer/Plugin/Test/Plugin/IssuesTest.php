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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\Plugin;

use Composer\Factory;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;

/**
 * Test class for various micro issues that do not require an own test class.
 *
 * @package ContaoCommunityAlliance\Composer\Plugin\Test\Plugin
 */
class IssuesTest extends TestCase
{
    /**
     * When the plugin is loaded, the event listeners are registered which require the Housekeeper.
     *
     * When the plugin gets uninstalled the housekeeper does not exist anymore and a "Housekeeper class not found" is
     * thrown.
     *
     * Test for https://github.com/contao-community-alliance/composer-plugin/issues/30
     *
     * Situation:
     *  - plugin is installed.
     *  - plugin get uninstalled.
     *
     * Result:
     *  - Housekeeper class not found.
     *
     * @return void
     */
    public function testIssue30LoadHousekeeper()
    {
        $inOut    = $this->getMock('Composer\IO\IOInterface');
        $factory  = new Factory();
        $composer = $factory->createComposer($inOut);
        $plugin   = new Plugin();

        $plugin->activate($composer, $inOut);

        $this->assertTrue(class_exists('ContaoCommunityAlliance\Composer\Plugin\Housekeeper', false));
    }
}
