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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\ConfigManipulator;

use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\ConfigManipulator;

class RestoreNeededConfigKeysTest extends TestCase
{
    public function testSetName()
    {
        $configJson = array(
            'license'     => 'proprietary',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEquals(1, count($messages));
    }

    public function testDoNotOverrideName()
    {
        $configJson = array(
            'name'        => 'some_vendor/custom_project',
            'license'     => 'proprietary',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'some_vendor/custom_project',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }

    public function testSetType()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'proprietary',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEquals(1, count($messages));
    }

    public function testDoNotOverrideType()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'proprietary',
            'type'        => 'custom-type',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'custom-type',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }

    public function testSetLicense()
    {
        $configJson = array(
            'name'        => 'local/website',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEquals(1, count($messages));
    }

    public function testDoNotOverrideLicense()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'LGPL-3.0',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'LGPL-3.0',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }

    public function testSetDescription()
    {
        $configJson = array(
            'name'        => 'local/website',
            'type'        => 'project',
            'license'     => 'proprietary',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'A local website project',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEquals(1, count($messages));
    }

    public function testDoNotOverrideDescription()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'LGPL-3.0',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/components'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'LGPL-3.0',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }

    public function testSetComponentsDir()
    {
        $configJson = array(
            'name'        => 'local/website',
            'type'        => 'project',
            'license'     => 'proprietary',
            'description' => 'My Website',
        );

        $messages = array();

        self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'proprietary',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/components'
                )
            ),
            $configJson
        );
        self::assertEquals(1, count($messages));
    }

    public function testDoNotOverrideComponentsDir()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'LGPL-3.0',
            'type'        => 'project',
            'description' => 'My Website',
            'config'      => array
            (
                'component-dir' => '../assets/local-components'
            )
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'LGPL-3.0',
                'type'        => 'project',
                'description' => 'My Website',
                'config'      => array
                (
                    'component-dir' => '../assets/local-components'
                )
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }

    public function testDoNotSetComponentsDirForContaoModule()
    {
        $configJson = array(
            'name'        => 'local/website',
            'license'     => 'LGPL-3.0',
            'type'        => 'contao-module',
            'description' => 'My Website',
        );

        $messages = array();

        self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

        self::assertEquals(
            array(
                'name'        => 'local/website',
                'license'     => 'LGPL-3.0',
                'type'        => 'contao-module',
                'description' => 'My Website',
            ),
            $configJson
        );
        self::assertEmpty($messages);
    }
}
