<?php

/**
 * This file is part of contao-community-alliance/composer-plugin.
 *
 * (c) 2013 Contao Community Alliance
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/composer-plugin
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use ContaoCommunityAlliance\Composer\Plugin\Installer\AbstractModuleInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

class PluginTest extends TestCase
{
    /**
     * Test that the plugin implements the PluginInterface.
     *
     * @return void
     */
    public function testImplementsPluginInterface()
    {
        $plugin = new Plugin();
        $this->assertTrue($plugin instanceof PluginInterface);
    }

    /**
     * Test that the activation registers both installers.
     *
     * @return void
     */
    public function testAddsContaoModuleInstallerOnActivation()
    {
        $installationManager = $this->getMock('Composer\\Installer\\InstallationManager');

        $installationManager
            ->expects($this->exactly(2))
            ->method('addInstaller')
            ->with($this->callback(
                function ($installer) {
                    return $installer instanceof AbstractModuleInstaller;
                }
            ))
        ;

        $plugin = new Plugin();
        $plugin->activate($this->mockComposer($installationManager), $this->mockIO());
    }

    /**
     * Test that the plugin implements the EventSubscriberInterface.
     *
     * @return void
     */
    public function testImplementsEventSubscriberInterface()
    {
        $plugin = new Plugin();
        $this->assertTrue($plugin instanceof EventSubscriberInterface);
    }

    /**
     * Test that the runonce file gets dumped on post install.
     *
     * @return void
     */
    public function testDumpsRunonceOnPostInstallEvent()
    {
        $runonce = $this->mockRunonce();
        $plugin  = new Plugin($runonce);
        $events  = $plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, $events);

        $runonce
            ->expects($this->once())
            ->method('dump');

        call_user_func([$plugin, $events[ScriptEvents::POST_INSTALL_CMD]]);
    }

    /**
     * Test that the runonce file gets dumped on post update.
     *
     * @return void
     */
    public function testDumpsRunonceOnPostUpdateEvent()
    {
        $runonce = $this->mockRunonce();
        $plugin  = new Plugin($runonce);
        $events  = $plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, $events);

        $runonce
            ->expects($this->once())
            ->method('dump');

        call_user_func([$plugin, $events[ScriptEvents::POST_UPDATE_CMD]]);
    }

    /**
     * Mock a composer instance.
     *
     * @param \Composer\Installer\InstallationManager $installationManager The installation manager.
     *
     * @return Composer|\PHPUnit_Framework_MockObject_MockObject
     */
    private function mockComposer($installationManager)
    {
        $tempdir         = $this->tempdir;
        $config          = $this->getMock('Composer\\Config');
        $downloadManager = $this->getMock('Composer\\Downloader\\DownloadManager', [], [], '', false);
        $composer        = $this->getMock(
            'Composer\\Composer',
            ['getConfig', 'getDownloadManager', 'getInstallationManager']
        );

        $composer
            ->expects($this->any())
            ->method('getConfig')
            ->willReturn($config);

        $composer
            ->expects($this->any())
            ->method('getDownloadManager')
            ->willReturn($downloadManager);

        $composer
            ->expects($this->any())
            ->method('getInstallationManager')
            ->willReturn($installationManager);

        $config
            ->expects($this->any())
            ->method('get')
            ->with($this->logicalOr(
                $this->equalTo('vendor-dir'),
                $this->equalTo('bin-dir'),
                $this->equalTo('bin-compat')
            ))
            ->willReturnCallback(
                function ($key) use ($tempdir) {
                    switch ($key) {
                        case 'vendor-dir':
                            return $tempdir . '/vendor';

                        case 'bin-dir':
                            return $tempdir . '/vendor/bin';

                        case 'bin-compat':
                            return 'auto';

                        default:
                    }

                    return null;
                }
            );

        return $composer;
    }

    /**
     * Mock an input/output instance which is very verbose and ensures that only writeError is used (not write()).
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private function mockIO()
    {
        $ioMock = $this->getMock('Composer\\IO\\IOInterface');

        $ioMock
            ->expects($this->any())
            ->method('isVerbose')
            ->willReturn(true);

        $ioMock
            ->expects($this->any())
            ->method('isVeryVerbose')
            ->willReturn(true);

        // Should always use writeError() and not write()
        $ioMock
            ->expects($this->never())
            ->method('write');

        return $ioMock;
    }

    /**
     * Create a mock of the runonce manager.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|RunonceManager
     */
    private function mockRunonce()
    {
        return $this->getMockBuilder('ContaoCommunityAlliance\\Composer\\Plugin\\RunonceManager')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
