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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\AbstractInstaller;

use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\AbstractInstaller;

class UnprefixPathTest extends TestCase
{
    public function test()
    {
        foreach (array(
            array(
                'prefix' => '',
                'path'   => '',
                'result' => ''
            ),
            array(
                'prefix' => '/home/user',
                'path'   => '/var/lib/file.txt',
                'result' => '/var/lib/file.txt'
            ),
            array(
                'prefix' => 'C:\Foo\Bar',
                'path'   => 'C:\Foo\Bar\fooBar.txt',
                'result' => '\fooBar.txt'
            ),
            array(
                'prefix' => '/home/user',
                'path'   => '/home/user/file.txt',
                'result' => '/file.txt'
            ),
        )
            as $testValues
        )
        {
            self::assertEquals(
                $testValues['result'],
                AbstractInstaller::unprefixPath($testValues['prefix'], $testValues['path'])
            );
        }
    }
}
