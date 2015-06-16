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

use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;

class VersionParsingTest extends TestCase
{
    public function prepareVersions()
    {
        $versions = array
        (
            array(
                'version' => '2.10',
                'build'   => '5',
                'expect'  => '2.10.5'
            ),
            array(
                'version' => '2.10',
                'build'   => '50',
                'expect'  => '2.10.50'
            ),
            array(
                'version' => '2.10',
                'build'   => 'beta1',
                'expect'  => '2.10.beta1'
            ),
            array(
                'version' => '2.10',
                'build'   => 'RC1',
                'expect'  => '2.10.RC1'
            ),
            array(
                'version' => '2.10',
                'build'   => '5-cca-soa-hotfix2',
                'expect'  => '2.10.5'
            ),
            array(
                'version' => '2.10',
                'build'   => '50-cca-soa-hotfix2',
                'expect'  => '2.10.50'
            ),
        );

        return array_map(function($arr){return array($arr['version'], $arr['build'], $arr['expect']);}, $versions);
    }

    /**
     * @dataProvider prepareVersions
     *
     * @param string $version
     * @param string $build
     * @param string $expected
     */
     public function testVersionParsing($version, $build, $expected)
    {
        $plugin = new Plugin();

        $prepareContaoVersion = new \ReflectionMethod($plugin, 'prepareContaoVersion');
        $prepareContaoVersion->setAccessible(true);

        $this->assertEquals($expected, $prepareContaoVersion->invoke($plugin, $version, $build));
    }
}
