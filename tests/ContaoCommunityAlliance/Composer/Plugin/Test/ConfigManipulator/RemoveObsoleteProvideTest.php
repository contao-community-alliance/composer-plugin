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

class RemoveObsoleteProvideTest extends TestCase
{
    public function testNothingToDo()
    {
        $configJson = array(
            'name'        => 'some_vendor/custom_project',
            'type'        => 'project',
            'license'     => 'LGPL-3.0',
            'version'     => '0.0.0.0',
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteProvides($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'name'        => 'some_vendor/custom_project',
                'type'        => 'project',
                'license'     => 'LGPL-3.0',
                'version'     => '0.0.0.0',
            ),
            $configJson
        );
    }

    public function testRemoveSwiftMailer()
    {
        $configJson = array(
            'name'        => 'contao/core',
            'type'        => 'metapackage',
            'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteProvides($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'name'        => 'contao/core',
                'type'        => 'metapackage',
            ),
            $configJson
        );
    }

    public function testNotRemoveSwiftMailerFromCustomProject()
    {
        $configJson = array(
            'name'        => 'local/website',
            'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteProvides($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
            ),
            $configJson
        );
    }
}
