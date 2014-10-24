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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\ConfigManipulator;

use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\ConfigManipulator;

class RemoveObsoleteRequiresTest extends TestCase
{
    public function testNothingToDo()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteRequires($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*'
                )
            ),
            $configJson
        );
    }

    public function testRemoveContaoCommunityAllianceComposer()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*',
                'contao-community-alliance/composer' => '*'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteRequires($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*'
                )
            ),
            $configJson
        );
    }

    public function testDoNotRemoveContaoCommunityAllianceComposer()
    {
        $configJson = array(
            'require' => array
            (
                'some/package' => '*',
                'contao-community-alliance/composer' => 'dev-branchname'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteRequires($configJson, $messages));
        self::assertEquals(0, count($messages));

        self::assertEquals(
            array(
                'require' => array
                (
                    'some/package' => '*',
                'contao-community-alliance/composer' => 'dev-branchname'
                )
            ),
            $configJson
        );
    }
}
