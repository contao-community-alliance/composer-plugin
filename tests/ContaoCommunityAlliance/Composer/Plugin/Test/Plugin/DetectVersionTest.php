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

use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;

class DetectVersionTest extends TestCase
{
    /**
     * Path to a temporary folder where to mimic an installation.
     *
     * @var string
     */
    protected $testRoot;

    /**
     * Current working dir.
     *
     * @var string
     */
    protected $curDir;

    /** @var Filesystem */
    protected $fs;

    protected function setUp()
    {
        $this->fs       = new Filesystem();
        $this->curDir   = getcwd();
        $this->testRoot = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-submodule';
    }

    protected function tearDown()
    {
        chdir($this->curDir);
        $this->fs->removeDirectory($this->testRoot);
    }

    /**
     * Prepare the plugin.
     *
     * @return Plugin
     */
    protected function mockPlugin()
    {
        $plugin = $this->getMock(
            '\ContaoCommunityAlliance\Composer\Plugin\Plugin',
            array('getUploadPath', 'getContaoRoot', 'loadConfig')
        );

        return $plugin;
    }

    /**
     * @param Plugin $plugin
     * @param string $systemDir
     * @param string $configDir
     * @param string $expectVersion
     * @param string $expectBuild
     */
    protected function runWith($plugin, $systemDir, $configDir, $expectVersion, $expectBuild)
    {
        $detectVersion = new \ReflectionMethod($plugin, 'detectVersion');
        $detectVersion->setAccessible(true);

        $detectVersion->invokeArgs($plugin, array($systemDir, $configDir, dirname($systemDir)));

        $this->assertEquals($expectVersion, $plugin->getContaoVersion());
        $this->assertEquals($expectBuild, $plugin->getContaoBuild());
    }

    /**
     * Prepare the test directory and the plugin.
     *
     * @param $systemDir
     *
     * @param $configDir
     *
     * @param $version
     *
     * @param $build
     *
     * @return Plugin
     */
    protected function clearTest($systemDir, $configDir, $version, $build)
    {
        switch (substr($version, 0, 1))
        {
            case '2':
                $realConstantsPath = $systemDir;
            break;

            default:
                $realConstantsPath = $configDir;
        }
        $this->ensureDirectoryExistsAndClear($realConstantsPath);
        if (!chdir($this->testRoot))
        {
            $this->markTestIncomplete('Could not change to temp dir. Test incomplete!');
        }

        file_put_contents($realConstantsPath  . DIRECTORY_SEPARATOR . 'constants.php', '
<?php

/**
 * Contao Open Source CMS (Micro Mock)
 *
 * Copyright (c) 0000-9999 A. L. User
 *
 * @package Core
 * @link    https://example.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * Core version
 */
define(\'VERSION\', \'' . $version . '\');
define(\'BUILD\', \'' . $build . '\');
define(\'LONG_TERM_SUPPORT\', true);

');

        return $this->mockPlugin();
    }

    /**
     * Test that a locally installed contao can be found when overriding the path via composer.json in the root package.
     *
     * @return void
     */
    public function testIsContao2()
    {
        $systemDir = $this->testRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
        $configDir = $systemDir . 'config' . DIRECTORY_SEPARATOR;

        $plugin = $this->clearTest($systemDir, $configDir, '2.11', '42');

        $this->runWith($plugin, $systemDir, $configDir, '2.11', '42');
    }

    /**
     * Test that a contao installation can be found within composer/vendor/contao/core
     */
    public function testIsContao3()
    {
        $systemDir = $this->testRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR;
        $configDir = $systemDir . 'config' . DIRECTORY_SEPARATOR;

        $plugin = $this->clearTest($systemDir, $configDir, '3.2', '99');

        $this->runWith($plugin, $systemDir, $configDir, '3.2', '99');
    }
}
