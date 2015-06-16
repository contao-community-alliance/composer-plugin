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

class ExtractKeyFromConfigFileTest extends TestCase
{
    protected function runWith($fixture, $key, $expectedValue)
    {
        $plugin = new Plugin();

        $extractKeyFromConfigFile = new \ReflectionMethod($plugin, 'extractKeyFromConfigFile');
        $extractKeyFromConfigFile->setAccessible(true);

        $this->assertEquals(
            $expectedValue,
            $extractKeyFromConfigFile->invokeArgs($plugin, array(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . $fixture,
                $key
            ))
        );
    }

    public function testDefault()
    {
        $this->runWith('default-contao-3.2.php', 'websiteTitle', 'Contao Open Source CMS');
        $this->runWith('default-contao-3.2.php', 'characterSet', 'utf-8');
        $this->runWith('default-contao-3.2.php', 'adminEmail', '');
        $this->runWith('default-contao-3.2.php', 'enableSearch', true);
        $this->runWith('default-contao-3.2.php', 'indexProtected', false);
        $this->runWith('default-contao-3.2.php', 'dbPort', 3306);
        $this->runWith('default-contao-3.2.php', 'requestTokenWhitelist', array());

        $this->runWith('localconfig-override.php', 'websiteTitle', 'Overridden');
    }
}
