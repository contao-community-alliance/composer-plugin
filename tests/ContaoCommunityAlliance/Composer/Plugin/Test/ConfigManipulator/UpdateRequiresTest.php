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

class UpdateRequiresTest extends TestCase
{
    public function testAddClient()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*',
                    'contao-community-alliance/composer-client' => '~0.14',
                )
            ),
            $configJson
        );
    }

    public function testUpgradeToClient()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*',
                'contao-community-alliance/composer' => 'dev-keep-version'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*',
                    'contao-community-alliance/composer-client' => 'dev-keep-version'
                )
            ),
            $configJson
        );
    }

    public function testRemoveOldAndKeepNew()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*',
                'contao-community-alliance/composer' => 'dev-branchname',
                'contao-community-alliance/composer-client' => 'dev-something'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*',
                    'contao-community-alliance/composer-client' => 'dev-something'
                )
            ),
            $configJson
        );
    }

    public function testDoNothing()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*',
                'contao-community-alliance/composer-client' => '*@dev'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*',
                    'contao-community-alliance/composer-client' => '*@dev'
                )
            ),
            $configJson
        );
    }

    public function testUpgradeToClientWithVersion()
    {
        $configJson = array(
            'require' => array
            (
                'contao-community-alliance/composer' => '>=0.13,<0.14-dev'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEquals(2, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'contao-community-alliance/composer-client' => '~0.14'
                )
            ),
            $configJson
        );
    }

    public function testUpgradeVersion()
    {
        $configJson = array(
            'require' => array
            (
                'contao-community-alliance/composer-client' => '>=0.13,<0.14-dev'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'contao-community-alliance/composer-client' => '~0.14'
                )
            ),
            $configJson
        );
    }

    public function testNoDowngradeVersion()
    {
        $configJson = array(
            'require' => array
            (
                'contao-community-alliance/composer-client' => '~0.15'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'require' => array
                (
                    'contao-community-alliance/composer-client' => '~0.15'
                )
            ),
            $configJson
        );
    }

    public function testDoNothingForContaoModule()
    {
        $configJson = array(
            'type' => 'contao-module',
            'require' => array
            (
                'some/package' => '~1.0',
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::updateRequirements($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'type' => 'contao-module',
                'require' => array
                (
                    'some/package' => '~1.0',
                )
            ),
            $configJson
        );
    }
}
