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

class RemoveObsoleteConfigEntriesTest extends TestCase
{
    public function testNothingToDo()
    {
        $configJson = array(
            'extra' => array
            (
                'contao' => array(),
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteConfigEntries($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'extra' => array
                (
                    'contao' => array(),
                )
            ),
            $configJson
        );
    }

    public function testRemoveArtifactPath()
    {
        $configJson = array(
            'extra' => array
            (
                'contao' => array(
                    'somedata' => 'some-value',
                    'artifactPath' => '/home/contao/packages'
                ),
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteConfigEntries($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'extra' => array
                (
                    'contao' => array(
                        'somedata' => 'some-value',
                    ),
                )
            ),
            $configJson
        );
    }
}
