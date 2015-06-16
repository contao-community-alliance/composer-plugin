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

class RestoreRepositoriesTest extends TestCase
{
    public function testAddBoth()
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

        self::assertTrue(ConfigManipulator::restoreRepositories($configJson, $messages));
        self::assertEquals(2, count($messages));

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'https://legacy-packages-via.contao-community-alliance.org/'
                    ),
                    array(
                        'type' => 'artifact',
                        'url'  => 'packages'
                    ),
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testAddLegacyRepository()
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
                ),
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreRepositories($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'https://legacy-packages-via.contao-community-alliance.org/'
                    ),
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                    array(
                        'type' => 'artifact',
                        'url'  => '/home/vhost/contao3.site.ub-gauss.ath.cx/composer/packages'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testAddArtifactRepository()
    {
        $configJson = array(
            'repositories' => array
            (
                array(
                    'type' => 'composer',
                    'url'  => 'http://my.packages.org/'
                ),
                array(
                    'type' => 'composer',
                    'url'  => 'http://legacy-packages-via.contao-community-alliance.org/'
                ),
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreRepositories($configJson, $messages));
        self::assertEquals(1, count($messages));

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'artifact',
                        'url'  => 'packages'
                    ),
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                    array(
                        'type' => 'composer',
                        'url'  => 'http://legacy-packages-via.contao-community-alliance.org/'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testDoNothing()
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
                ),
                array(
                    'type' => 'composer',
                    'url'  => 'http://legacy-packages-via.contao-community-alliance.org/'
                ),
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreRepositories($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'repositories' => array
                (
                    array(
                        'type' => 'composer',
                        'url'  => 'http://my.packages.org/'
                    ),
                    array(
                        'type' => 'artifact',
                        'url'  => '/home/vhost/contao3.site.ub-gauss.ath.cx/composer/packages'
                    ),
                    array(
                        'type' => 'composer',
                        'url'  => 'http://legacy-packages-via.contao-community-alliance.org/'
                    ),
                )
            ),
            $configJson
        );
    }

    public function testDoNothingForContaoModule()
    {
        $configJson = array(
            'type' => 'contao-module',
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreRepositories($configJson, $messages));
        self::assertEmpty($messages);

        self::assertEquals(
            array(
                'type' => 'contao-module',
            ),
            $configJson
        );
    }
}
