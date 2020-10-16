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
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Sven Baumann <baumann.sv@gmail.com>
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
use ContaoCommunityAlliance\Composer\Plugin\Installer\ContaoModuleInstaller;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;
use Composer\Config;
use Composer\Downloader\DownloadManager;

/**
 * This tests the ContaoModuleInstaller.
 */
class ContaoModuleInstallerTest extends TestCase
{
    /**
     * Tests that the installer supports packages of type "contao-module".
     *
     * @return void
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
     *
     * @return void
     */
    public function testRunonceOnInstall()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
            [
                'runonce' => ['src/Resources/contao/config/update.php']
            ]
        );

        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($package) . '/src/Resources/contao/config/update.php');

        $installer->install($repo, $package);
    }

    /**
     * Tests that runonce files are added to the RunonceManager when updating a package.
     *
     * @return void
     */
    public function testRunonceOnUpdate()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $initial   = $this->mockPackage();
        $target    = $this->mockPackage(
            [
                'runonce' => ['src/Resources/contao/config/update.php']
            ]
        );

        $runonce
            ->expects($this->once())
            ->method('addFile')
            ->with($installer->getInstallPath($target) . '/src/Resources/contao/config/update.php');

        $installer->update($repo, $initial, $target);
    }

    /**
     * Tests that sources are symlinked when installing a package.
     *
     * @return void
     */
    public function testSourcesOnInstall()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
            [
                'sources' => [
                    'config/config.php' => 'system/modules/foobar/config/config.php'
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);
        $contaoPath = dirname(dirname(dirname($basePath)));

        $this->filesystem->ensureDirectoryExists($basePath . '/config');
        touch($basePath . '/config/config.php');

        $installer->install($repo, $package);

        $this->assertTrue(file_exists($contaoPath . '/system/modules/foobar/config/config.php'));
        $this->assertTrue(is_link($contaoPath . '/system/modules/foobar/config/config.php'));
        $this->assertTrue($installer->isInstalled($repo, $package));
        $this->assertEquals(
            $basePath . '/config/config.php',
            realpath($contaoPath . '/system/modules/foobar/config/config.php')
        );
    }

    /**
     * Tests that nothing happens if a symlink is already present and correct.
     *
     * @return void
     */
    public function testSourcesOnInstallIgnoresIfLinkIsAlreadyCorrect()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
        $this->assertTrue($installer->isInstalled($repo, $package));
        $this->assertEquals(
            $basePath . '/config/config.php',
            realpath($basePath . '/../../../system/modules/foobar/config/config.php')
        );
    }

    /**
     * Tests that a package is considered uninstalled if no symlink was created
     * or has been deleted.
     *
     * @return void
     */
    public function testPackageIsConsideredUninstalledIfSourceLinksAreMissing()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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

        $this->assertFalse($installer->isInstalled($repo, $package));
    }

    /**
     * Tests that an exception is thrown if a target already exists.
     *
     * @expectedException \RuntimeException
     *
     * @return void
     */
    public function testSourcesOnInstallThrowsExceptionIfFileExists()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
     * @expectedException \RuntimeException
     *
     * @return void
     */
    public function testSourcesOnInstallThrowsExceptionIfFileIsUnreadable()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
     * Test that an exception is thrown when the package is not installed.
     *
     * @expectedException \InvalidArgumentException
     *
     * @return void
     */
    public function testSourcesOnUpdateThrowsExceptionIfPackageIsNotInstalled()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $package   = $this->mockPackage();

        /** @var \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface $repo */
        $repo = $this->getMockBuilder(InstalledRepositoryInterface::class)->getMock();

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(false);

        $installer->update($repo, $package, $package);
    }

    /**
     * Test that symlinks get removed on uninstall.
     *
     * @return void
     */
    public function testSourcesOnUninstall()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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

    /**
     * Test that a missing target file is ignored when a package is uninstalled.
     *
     * @return void
     */
    public function testSourcesOnUninstallIgnoresMissingTarget()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
     * Test that an exception is thrown when uninstalling and the link target is not a link anymore.
     *
     * @expectedException \RuntimeException
     *
     * @return void
     */
    public function testSourcesOnUninstallThrowsExceptionIfTargetIsNotALink()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
     * Test that an exception is thrown when uninstalling and the link target is now a link to a different file.
     *
     * @expectedException \RuntimeException
     *
     * @return void
     */
    public function testSourcesOnUninstallThrowsExceptionIfTargetLinkIsDifferent()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
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
     * Test that an exception is thrown when attempting to uninstall an not installed package.
     *
     * @expectedException \InvalidArgumentException
     *
     * @return void
     */
    public function testSourcesOnUninstallThrowsExceptionIfPackageIsNotInstalled()
    {
        $runonce   = $this->mockRunonce();
        $installer = $this->createInstaller($runonce);
        $package   = $this->mockPackage();

        /** @var \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface $repo */
        $repo = $this->getMockBuilder(InstalledRepositoryInterface::class)->getMock();

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(false);

        $installer->uninstall($repo, $package);
    }

    /**
     * Tests that userfiles are copied when installing a package.
     */
    public function testUserfilesOnInstall()
    {
        $installer = $this->createInstaller($this->mockRunonce());
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
            [
                'userfiles' => [
                    'foo/bar.txt' => 'foo/bar.txt',
                    'foo/bar' => 'foo/bar',
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);
        $projectDir = dirname(dirname(dirname($basePath)));

        $this->filesystem->ensureDirectoryExists($basePath . '/foo/bar');
        touch($basePath . '/foo/bar.txt');
        touch($basePath . '/foo/bar/1.txt');
        touch($basePath . '/foo/bar/2.txt');

        $installer->install($repo, $package);

        $this->assertFileExists($projectDir . '/files/foo/bar.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');
        $this->assertFalse(is_link($projectDir . '/files/foo/bar.txt'));
    }

    /**
     * Tests that userfiles are not overwritten when installing a package.
     */
    public function testUserfilesOnInstallDoesNotOverwrite()
    {
        $installer = $this->createInstaller($this->mockRunonce());
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
            [
                'userfiles' => [
                    'foo/bar.txt' => 'foo/bar.txt',
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);
        $projectDir = dirname(dirname(dirname($basePath)));

        $this->filesystem->ensureDirectoryExists($basePath . '/foo');
        $this->filesystem->ensureDirectoryExists($projectDir . '/files/foo');
        touch($basePath . '/foo/bar.txt');
        file_put_contents($projectDir . '/files/foo/bar.txt', 'foobar');

        $installer->install($repo, $package);

        $this->assertFileExists($projectDir . '/files/foo/bar.txt');
        $this->assertFalse(is_link($projectDir . '/files/foo/bar.txt'));
        $this->assertSame('foobar', file_get_contents($projectDir.'/files/foo/bar.txt'));
    }

    /**
     * Tests that userfiles are NOT removed when uninstalling a package.
     */
    public function testUserfilesOnUninstall()
    {
        $installer = $this->createInstaller($this->mockRunonce());
        $repo      = $this->mockRepository();
        $package   = $this->mockPackage(
            [
                'userfiles' => [
                    'foo/bar.txt' => 'foo/bar.txt',
                    'foo/bar' => 'foo/bar',
                ]
            ]
        );

        $basePath = $installer->getInstallPath($package);
        $projectDir = dirname(dirname(dirname($basePath)));

        $this->filesystem->ensureDirectoryExists($basePath . '/foo/bar');
        touch($basePath . '/foo/bar.txt');
        touch($basePath . '/foo/bar/1.txt');
        touch($basePath . '/foo/bar/2.txt');

        $installer->install($repo, $package);

        $this->assertFileExists($projectDir . '/files/foo/bar.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');

        $installer->uninstall($repo, $package);

        $this->assertFileExists($projectDir . '/files/foo/bar.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');
        $this->assertFileExists($projectDir . '/files/foo/bar/1.txt');
    }

    /**
     * Create a mock of the runonce manager.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|RunonceManager
     */
    private function mockRunonce()
    {
        return $this->getMockBuilder(RunonceManager::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Mock a composer instance.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|Composer
     */
    private function mockComposer()
    {
        $tempdir         = $this->tempdir;
        $config          = $this->getMockBuilder(Config::class)->getMock();
        $downloadManager = $this->getMockBuilder(DownloadManager::class)->disableOriginalConstructor()->getMock();
        $composer        = $this
            ->getMockBuilder(Composer::class)->setMethods(['getConfig', 'getDownloadManager'])->getMock();

        $composer
            ->expects($this->any())
            ->method('getConfig')
            ->willReturn($config);

        $composer
            ->expects($this->any())
            ->method('getDownloadManager')
            ->willReturn($downloadManager);

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
     * Mock a package containing the passed extra section.
     *
     * @param array $contaoExtras The value to use as extra section.
     *
     * @return PackageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private function mockPackage(array $contaoExtras = [])
    {
        $package = $this->getMockBuilder(PackageInterface::class)->getMock();

        $package
            ->expects($this->any())
            ->method('getTargetDir')
            ->willReturn('');

        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('foo/bar');

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->willReturn('foo/bar');

        $package
            ->expects(empty($contaoExtras) ? $this->any() : $this->atLeastOnce())
            ->method('getExtra')
            ->willReturn(
                [
                    'contao' => $contaoExtras
                ]
            );

        return $package;
    }

    /**
     * Mock a repository which will always respond with true to calls of "hasPackage".
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|InstalledRepositoryInterface
     */
    private function mockRepository()
    {
        $repo = $this->getMockBuilder(InstalledRepositoryInterface::class)->getMock();

        $repo
            ->expects($this->any())
            ->method('hasPackage')
            ->willReturn(true);

        return $repo;
    }

    /**
     * Mock an input/output instance which is very verbose and ensures that only writeError is used (not write()).
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private function mockIO()
    {
        $ioMock = $this->getMockBuilder(IOInterface::class)->getMock();

        $ioMock
            ->expects($this->any())
            ->method('isVerbose')
            ->willReturn(true);

        $ioMock
            ->expects($this->any())
            ->method('isVeryVerbose')
            ->willReturn(true);

        // Should always use write() and not writeError()
        $ioMock
            ->expects($this->never())
            ->method('writeError');

        return $ioMock;
    }

    /**
     * Create a ContaoModuleInstaller with mocked instances.
     *
     * @param RunonceManager $runonce The run once manager to use.
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
