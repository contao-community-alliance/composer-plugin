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

class GetNativePathTest extends TestCase
{
    public function test()
    {
        foreach (
            array(
                array(
                    'path'      => '',
                    'separator' => '',
                    'result'    => ''
                ),
                array(
                    'path'      => '/var/lib/file.txt',
                    'separator' => '/',
                    'result'    => '/var/lib/file.txt'
                ),
                array(
                    'path'      => 'C:\Foo\Bar',
                    'separator' => '/',
                    'result'    => 'C:/Foo/Bar'
                ),
                array(
                    'path'      => '/home/user/file.txt',
                    'separator' => '/',
                    'result'    => '/home/user/file.txt'
                ),
                array(
                    'path'      => 'C:\Foo/Bar/mixed\content',
                    'separator' => '/',
                    'result'    => 'C:/Foo/Bar/mixed/content'
                ),
            )
            as $testValues
        )
        {
            self::assertEquals(
                $testValues['result'],
                AbstractInstaller::getNativePath($testValues['path'], $testValues['separator'])
            );
        }

        self::assertEquals(
            DIRECTORY_SEPARATOR . 'some' . DIRECTORY_SEPARATOR . 'file.txt',
            AbstractInstaller::getNativePath('/some/file.txt')
        );

        self::assertEquals(
            DIRECTORY_SEPARATOR . 'some' . DIRECTORY_SEPARATOR . 'file.txt',
            AbstractInstaller::getNativePath('\some\file.txt')
        );
    }
}
