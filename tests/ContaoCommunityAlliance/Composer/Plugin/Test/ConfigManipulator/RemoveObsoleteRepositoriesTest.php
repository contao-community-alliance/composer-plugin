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

class RemoveObsoleteRepositoriesTest extends TestCase
{
    public function testNothingToDo()
    {
        $configJson = array(
            'repositories' => array
            (
                array(
                    'type' => 'composer',
                    'url'  => 'http://my.packages.org/'
                ),
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::removeObsoleteRepositories($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testRemoveArtifact()
    {
        $configJson = array(
            'repositories' => array
            (
                array(
                    'type' => 'composer',
                    'url'  => 'http://my.packages.org/'
                ),
                array(
                    'type' => 'artifact',
                    'url'  => '/home/vhost/contao3.site.ub-gauss.ath.cx/composer/packages'
                )
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteRepositories($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testRemoveLegacyPackages()
    {
        $configJson = array(
            'repositories' => array
            (
                array(
                    'type' => 'composer',
                    'url'  => 'http://legacy-packages-via.contao-community-alliance.org/'
                ),
                array(
                    'type' => 'composer',
                    'url'  => 'http://my.packages.org/'
                ),
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::removeObsoleteRepositories($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                )
            ),
            $configJson
        );
    }
}
