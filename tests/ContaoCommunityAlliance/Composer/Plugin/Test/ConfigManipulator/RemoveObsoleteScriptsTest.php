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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\ConfigManipulator;

use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\ConfigManipulator;

class RemoveObsoleteScriptsTest extends TestCase
{
    public function testNothingToDo()
    {
        $configJson = array(
            'scripts' => array
            (
                'pre-update-cmd' => 'DoNotTouch\Me::please',
                'post-update-cmd' => 'DoNotTouch\Me::please',
                'post-autoload-dump' => 'DoNotTouch\Me::please',
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteScripts($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'scripts' => array
                (
                    'pre-update-cmd' => 'DoNotTouch\Me::please',
                    'post-update-cmd' => 'DoNotTouch\Me::please',
                    'post-autoload-dump' => 'DoNotTouch\Me::please',
                )
            ),
            $configJson
        );
    }

    public function testLinear()
    {
        $configJson = array(
            'scripts' => array
            (
                'pre-update-cmd' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
                'post-update-cmd' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate',
                'post-autoload-dump' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteScripts($configJson, $messages));
        self::assertEquals(3, count($messages));

        self::assertEmpty(
            $configJson
        );
    }

    public function testArray()
    {
        $configJson = array(
            'scripts' => array
            (
                'pre-update-cmd' => array('ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate'),
                'post-update-cmd' => array('ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate'),
                'post-autoload-dump' => array('ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump'),
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteScripts($configJson, $messages));
        self::assertEquals(3, count($messages));

        self::assertEmpty(
            $configJson
        );
    }

    public function testKeepPrivate()
    {
        $configJson = array(
            'scripts' => array
            (
                'pre-update-cmd' => array(
                    'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
                    'TestVendor\Hook::run'
                ),
                'post-update-cmd' => 'OtherVendor\Hook::run',
                'post-autoload-dump' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteScripts($configJson, $messages));
        self::assertEquals(2, count($messages));

        self::assertEquals(
            array(
                'scripts' => array(
                    'pre-update-cmd' => array(
                        1 => 'TestVendor\Hook::run'
                    ),
                    'post-update-cmd' => 'OtherVendor\Hook::run',
                )
            ),
            $configJson
        );
    }
}
