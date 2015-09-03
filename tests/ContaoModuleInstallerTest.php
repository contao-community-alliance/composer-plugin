<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\ContaoModuleInstaller;

class ContaoModuleInstallerTest extends \PHPUnit_Framework_TestCase
{
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
        $this->tempdir    = sys_get_temp_dir() . '/' . substr(md5(mt_rand()), 0, 8);
        $this->filesystem = new Filesystem();

        $this->filesystem->ensureDirectoryExists($this->tempdir);

        $this->tempdir = realpath($this->tempdir);
    }

    protected function tearDown()
    {
        $this->filesystem->removeDirectory($this->tempdir);
    }

    public function testSupportsContaoModule()
    {
        $installer = $this->mockInstaller($this->mockRunonce());

        $this->assertTrue($installer->supports('contao-module'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('legacy-contao-module'));
    }

    public function testRunonceOnInstall()
    {
        $filesystem = $this->mockFilesystem();
        $runonce    = $this->mockRunonce($filesystem);
        $installer  = $this->mockInstaller($runonce, $filesystem);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage();

        $package
            ->expects($this->atLeastOnce())
            ->method('getExtra')
            ->willReturn(
                [
                    'contao' => [
                        'runonce' => ['src/Resources/contao/config/update.php']
                    ]
                ]
            )
        ;

        /** @noinspection PhpParamsInspection */
        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($package) . '/src/Resources/contao/config/update.php')
        ;

        /** @noinspection PhpParamsInspection */
        $installer->install($repo, $package);
    }

    public function testRunonceOnUpdate()
    {
        $filesystem = $this->mockFilesystem();
        $runonce    = $this->mockRunonce($filesystem);
        $installer  = $this->mockInstaller($runonce, $filesystem);
        $repo       = $this->mockRepository();
        $initial    = $this->mockPackage();
        $target     = $this->mockPackage();

        $target
            ->expects($this->atLeastOnce())
            ->method('getExtra')
            ->willReturn(
                [
                    'contao' => [
                        'runonce' => ['src/Resources/contao/config/update.php']
                    ]
                ]
            )
        ;

        /** @noinspection PhpParamsInspection */
        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($target) . '/src/Resources/contao/config/update.php')
        ;

        /** @noinspection PhpParamsInspection */
        $installer->update($repo, $initial, $target);
    }

    private function mockRunonce($filesystem = null)
    {
        return $this->getMock(
            'ContaoCommunityAlliance\\Composer\\Plugin\\RunonceManager',
            [],
            [tmpfile(), $filesystem]
        );
    }

    private function mockFilesystem()
    {
        $filesystem = $this->getMock('Composer\\Util\\Filesystem', ['normalizePath']);

        $filesystem
            ->expects($this->any())
            ->method('normalizePath')
            ->willReturnArgument(0)
        ;

        return $filesystem;
    }

    private function mockComposer()
    {
        $tempdir         = $this->tempdir;
        $config          = $this->getMock('Composer\\Config');
        $downloadManager = $this->getMock('Composer\\Downloader\\DownloadManager', [], [], '', false);
        $composer        = $this->getMock('Composer\\Composer', ['getConfig', 'getDownloadManager']);

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

    private function mockPackage()
    {
        $package = $this->getMock('Composer\\Package\\PackageInterface');

        $package
            ->expects($this->any())
            ->method('getTargetDir')
            ->willReturn('')
        ;

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->willReturn('foo/bar')
        ;

        return $package;
    }

    private function mockInstaller($runonce, $filesystem = null)
    {
        /** @noinspection PhpParamsInspection */
        $installer = new ContaoModuleInstaller(
            $runonce,
            $this->getMock('Composer\\IO\\IOInterface'),
            $this->mockComposer(),
            'contao-module',
            $filesystem
        );

        return $installer;
    }

    private function mockRepository()
    {
        $repo = $this->getMock('Composer\\Repository\\InstalledRepositoryInterface');

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(true)
        ;

        return $repo;
    }
}
