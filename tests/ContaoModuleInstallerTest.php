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
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\Installer\ContaoModuleInstaller;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

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

    public function tearDown()
    {
        $this->filesystem->removeDirectory($this->tempdir);
    }

    /**
     * Tests that the installer supports packages of type "contao-module".
     */
    public function testSupportsContaoModule()
    {
        $installer = $this->createInstaller($this->mockRunonce());

        $this->assertTrue($installer->supports('contao-module'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('legacy-contao-module'));
    }

    /**
     * Tests that runonce files are added to the RunonceManager on package installation.
     */
    public function testRunonceOnInstall()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'runonce' => ['src/Resources/contao/config/update.php']
            ]
        );

        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($package) . '/src/Resources/contao/config/update.php')
        ;

        $installer->install($repo, $package);
    }

    /**
     * Tests that runonce files are added to the RunonceManager when updating a package.
     */
    public function testRunonceOnUpdate()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $initial    = $this->mockPackage();
        $target     = $this->mockPackage(
            [
                'runonce' => ['src/Resources/contao/config/update.php']
            ]
        );

        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($target) . '/src/Resources/contao/config/update.php')
        ;

        $installer->update($repo, $initial, $target);
    }

    /**
     * Tests that sources are symlinked when installing a package.
     */
    public function testSourcesOnInstall()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $installer->install($repo, $package);

        $this->assertTrue(file_exists($basePath . '/../../../system/modules/foobar/config/config.php'));
        $this->assertTrue(is_link($basePath . '/../../../system/modules/foobar/config/config.php'));
        $this->assertEquals(
            $basePath . '/config/config.php',
            realpath($basePath . '/../../../system/modules/foobar/config/config.php')
        );
    }

    /**
     * Tests that nothing happens if a symlink is already present and correct.
     */
    public function testSourcesOnInstallIgnoresIfLinkIsAlreadyCorrect()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $this->filesystem->ensureDirectoryExists($basePath . '/../../../system/modules/foobar/config');
        symlink($basePath . '/config/config.php', $basePath . '/../../../system/modules/foobar/config/config.php');

        $installer->install($repo, $package);

        $this->assertTrue(file_exists($basePath . '/../../../system/modules/foobar/config/config.php'));
        $this->assertTrue(is_link($basePath . '/../../../system/modules/foobar/config/config.php'));
        $this->assertEquals(
            $basePath . '/config/config.php',
            realpath($basePath . '/../../../system/modules/foobar/config/config.php')
        );
    }

    /**
     * Tests that an exception is thrown if a target already exists.
     *
     * @expectedException \RuntimeException
     */
    public function testSourcesOnInstallThrowsExceptionIfFileExists()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $this->filesystem->ensureDirectoryExists($basePath . '/../../../system/modules/foobar/config');
        touch($basePath . '/../../../system/modules/foobar/config/config.php');

        $installer->install($repo, $package);
    }

    /**
     * Tests that an exception is thrown if a source is not readable.
     *
     * @runInSeparateProcess
     * @expectedException \RuntimeException
     */
    public function testSourcesOnInstallThrowsExceptionIfFileIsUnreadable()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');

        $installer->install($repo, $package);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSourcesOnUpdateThrowsExceptionIfPackageIsNotInstalled()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $package    = $this->mockPackage();

        /** @var \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface $repo */
        $repo = $this->getMock('Composer\\Repository\\InstalledRepositoryInterface');

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(false)
        ;

        $installer->update($repo, $package, $package);
    }

    public function testSourcesOnUninstall()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $this->filesystem->ensureDirectoryExists($basePath . '/../../../system/modules/foobar/config');
        symlink(
            $basePath . '/config/config.php',
            $this->filesystem->normalizePath($basePath . '/../../../system/modules/foobar/config/config.php')
        );

        $installer->uninstall($repo, $package);

        $this->assertFalse(file_exists($basePath . '/../../../system/modules/foobar/config/config.php'));
        $this->assertFalse(is_dir($basePath . '/../../../system/modules/foobar'));
    }

    public function testSourcesOnUninstallIgnoresMissingTarget()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $installer->uninstall($repo, $package);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testSourcesOnUninstallThrowsExceptionIfTargetIsNotALink()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $this->filesystem->ensureDirectoryExists($basePath . '/../../../system/modules/foobar/config');
        touch($this->filesystem->normalizePath($basePath . '/../../../system/modules/foobar/config/config.php'));

        $installer->uninstall($repo, $package);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testSourcesOnUninstallThrowsExceptionIfTargetLinkIsDifferent()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $repo       = $this->mockRepository();
        $package    = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $this->filesystem->ensureDirectoryExists($basePath . '/../../../system/modules/foobar/config');
        symlink(
            $basePath . '/config',
            $this->filesystem->normalizePath($basePath . '/../../../system/modules/foobar/config/config.php')
        );

        $installer->uninstall($repo, $package);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSourcesOnUninstallThrowsExceptionIfPackageIsNotInstalled()
    {
        $runonce    = $this->mockRunonce();
        $installer  = $this->createInstaller($runonce);
        $package    = $this->mockPackage();

        /** @var \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface $repo */
        $repo = $this->getMock('Composer\\Repository\\InstalledRepositoryInterface');

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(false)
        ;

        $installer->uninstall($repo, $package);
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

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Composer
     */
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
                    }

                    return null;
                }
            )
        ;

        return $composer;
    }

    /**
     * @param array $contaoExtras
     *
     * @return PackageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private function mockPackage(array $contaoExtras = [])
    {
        $package = $this->getMock('Composer\\Package\\PackageInterface');

        $package
            ->expects($this->any())
            ->method('getTargetDir')
            ->willReturn('')
        ;

        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('foo/bar')
        ;

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->willReturn('foo/bar')
        ;

        $package
            ->expects(empty($contaoExtras) ? $this->any() : $this->atLeastOnce())
            ->method('getExtra')
            ->willReturn(
                [
                    'contao' => $contaoExtras
                ]
            )
        ;

        return $package;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface
     */
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
     * @param RunonceManager $runonce
     *
     * @return ContaoModuleInstaller
     */
    private function createInstaller(RunonceManager $runonce)
    {
        $installer = new ContaoModuleInstaller(
            $runonce,
            $this->mockIO(),
            $this->mockComposer(),
            'contao-module',
            $this->filesystem
        );

        return $installer;
    }
}
