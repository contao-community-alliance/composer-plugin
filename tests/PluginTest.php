<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\ContaoModuleInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

class PluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Plugin
     */
    private $plugin;

    /**
     * @var string
     */
    private $tempdir;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function setUp()
    {
        $this->plugin     = new Plugin();
        $this->tempdir    = sys_get_temp_dir() . '/' . substr(md5(mt_rand()), 0, 8);
        $this->filesystem = new Filesystem();

        $this->filesystem->ensureDirectoryExists($this->tempdir);

        $this->tempdir = realpath($this->tempdir);
    }

    public function tearDown()
    {
        $this->filesystem->removeDirectory($this->tempdir);
    }

    public function testImplementsPluginInterface()
    {
        $this->assertTrue($this->plugin instanceof PluginInterface);
    }

    public function testAddsContaoModuleInstallerOnActivation()
    {
        $installationManager = $this->getMock('Composer\\Installer\\InstallationManager');

        $installationManager
            ->expects($this->once())
            ->method('addInstaller')
            ->with($this->callback(
                function ($installer) {
                    return $installer instanceof ContaoModuleInstaller;
                }
            ))
        ;

        $this->plugin->activate($this->mockComposer($installationManager), $this->mockIO());
    }

    public function testImplementsEventSubscriberInterface()
    {
        $this->assertTrue($this->plugin instanceof EventSubscriberInterface);
    }

    public function testDumpsRunonceOnPostInstallEvent()
    {
        $runonce = $this->mockRunonce();
        $plugin  = new Plugin($runonce);
        $events  = $plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, $events);

        $runonce
            ->expects($this->once())
            ->method('dump')
        ;

        call_user_func([$plugin, $events[ScriptEvents::POST_INSTALL_CMD]]);
    }

    public function testDumpsRunonceOnPostUpdateEvent()
    {
        $runonce = $this->mockRunonce();
        $plugin  = new Plugin($runonce);
        $events  = $plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, $events);

        $runonce
            ->expects($this->once())
            ->method('dump')
        ;

        call_user_func([$plugin, $events[ScriptEvents::POST_UPDATE_CMD]]);
    }

    /**
     * @param $installationManager
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
            ->willReturn($config)
        ;

        $composer
            ->expects($this->any())
            ->method('getDownloadManager')
            ->willReturn($downloadManager)
        ;

        $composer
            ->expects($this->any())
            ->method('getInstallationManager')
            ->willReturn($installationManager)
        ;

        $config
            ->expects($this->any())
            ->method('get')
            ->with($this->logicalOr(
                $this->equalTo('vendor-dir'),
                $this->equalTo('bin-dir')
            ))
            ->willReturnCallback(
                function ($key) use ($tempdir) {
                    switch ($key) {
                        case 'vendor-dir':
                            return $tempdir . '/vendor';

                        case 'bin-dir':
                            return $tempdir . '/vendor/bin';
                    }

                    return null;
                }
            )
        ;

        return $composer;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private function mockIO()
    {
        $io = $this->getMock('Composer\\IO\\IOInterface');

        $io
            ->expects($this->any())
            ->method('isVerbose')
            ->willReturn(true)
        ;

        $io
            ->expects($this->any())
            ->method('isVeryVerbose')
            ->willReturn(true)
        ;

        // Should always use writeError() and not write()
        $io
            ->expects($this->never())
            ->method('write')
        ;

        return $io;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|RunonceManager
     */
    private function mockRunonce()
    {
        return $this->getMock(
            'ContaoCommunityAlliance\\Composer\\Plugin\\RunonceManager',
            [],
            [tmpfile(), $this->filesystem]
        );
    }
}
