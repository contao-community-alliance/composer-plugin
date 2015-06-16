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

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\AbstractInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;

abstract class InstallCodeBase extends TestCase
{
    /** @var Composer */
    protected $composer;

    /** @var Config */
    protected $config;

    /** @var string */
    protected $vendorDir;

    /** @var string */
    protected $binDir;

    /** @var DownloadManager */
    protected $dm;

    /** @var InstalledRepositoryInterface */
    protected $repository;

    /** @var IOInterface */
    protected $io;

    /** @var Filesystem */
    protected $fs;

    /** @var string */
    protected $rootDir;

    /** @var string */
    protected $uploadDir;

    /** @var Plugin */
    protected $plugin;

    protected function setUp()
    {
        $this->fs = new Filesystem;

        $this->composer = new Composer();
        $this->config = new Config();
        $this->composer->setConfig($this->config);

        $this->vendorDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->config->merge(array(
            'config' => array(
                'vendor-dir' => $this->vendorDir,
                'bin-dir' => $this->binDir,
            ),
        ));

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->composer->setDownloadManager($this->dm);

        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');
        $this->io = $this->getMock('Composer\IO\IOInterface');

        $this->rootDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-contao';

        $this->uploadDir = 'upload';

        $this->plugin = $this->getMock('\ContaoCommunityAlliance\Composer\Plugin\Plugin');
        $this->plugin
            ->expects($this->any())
            ->method('getContaoRoot')
            ->will($this->returnValue($this->rootDir));

        $this->plugin
            ->expects($this->any())
            ->method('getUploadPath')
            ->will($this->returnValue($this->uploadDir));

        $package = new RootPackage('test/package', '1.0.0.0', '1.0.0');

        $this->composer->setPackage($package);
    }

    protected function tearDown()
    {
        $this->fs->removeDirectory($this->vendorDir);
        $this->fs->removeDirectory($this->binDir);
        $this->fs->removeDirectory($this->rootDir);
    }

    protected function createPackage($extra)
    {
        $package = new CompletePackage('test/package', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        $package->setTargetDir('Some/Namespace');

        return $package;
    }

    /**
     * @return AbstractInstaller
     */
    protected abstract function mockInstaller();

    /**
     * Ensure sources get installed.
     */
    public function testSourcesCopy()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'sources' => array(
                    'test' => 'system/modules/test'
                )
            )
        ));

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/test';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        file_put_contents($pkgRoot . '/testfile.php', '<?php echo \'test\';');

        $library->installCode($package);

        $this->assertFileEquals($pkgRoot . '/testfile.php', $this->rootDir . '/system/modules/test/testfile.php');
    }

    /**
     * Ensure sources get deleted.
     */
    public function testSourcesDelete()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'sources' => array(
                    'test' => 'system/modules/test'
                )
            )
        ));

        $packageNew = $this->createPackage(array());

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/test';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        $this->ensureDirectoryExistsAndClear($this->rootDir . '/system/modules/test');

        file_put_contents($pkgRoot . '/testfile.php', '<?php echo \'test\';');
        file_put_contents($this->rootDir . '/system/modules/test/testfile.php', '<?php echo \'test\';');

        $library->updateCode($package, $packageNew);

        $this->assertFileNotExists($this->rootDir . '/system/modules/test/testfile.php');
    }

    /**
     * Ensure user files get installed.
     */
    public function testUserFilesCopy()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'userfiles' => array(
                    'test' => 'testdir'
                )
            )
        ));

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/test';
        $this->ensureDirectoryExistsAndClear($pkgRoot);

        file_put_contents($pkgRoot . '/testfile.php', '<?php echo \'test\';');

        $library->installCode($package);

        $this->assertFileEquals($pkgRoot . '/testfile.php', $this->rootDir . '/' . $this->uploadDir . '/testdir/testfile.php');
    }

    /**
     * Ensure that removing of userfiles from package does not remove them from the file system when updating.
     */
    public function testUserFilesDoNotDelete()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'userfiles' => array(
                    'test' => 'testdir'
                )
            )
        ));

        $packageNew = $this->createPackage(array());

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/test';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        $this->ensureDirectoryExistsAndClear($this->rootDir . '/' . $this->uploadDir . '/testdir');

        file_put_contents($pkgRoot . '/testfile.php', '<?php echo \'test\';');
        file_put_contents($this->rootDir . '/' . $this->uploadDir . '/testdir/testfile.php', '<?php echo \'test\';');

        $library->updateCode($package, $packageNew);

        $this->assertFileEquals($pkgRoot . '/testfile.php', $this->rootDir . '/' . $this->uploadDir . '/testdir/testfile.php');
    }

    /**
     * Ensure that removing of files from package does not remove them from the file system when updating.
     */
    public function testUserFilesDoNotOverwrite()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'userfiles' => array(
                    'test' => 'testdir'
                )
            )
        ));

        $packageNew = $this->createPackage(array(
            'contao' => array(
                'userfiles' => array(
                    'test' => 'testdir'
                )
            )
        ));

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/test';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        $this->ensureDirectoryExistsAndClear($this->rootDir . '/' . $this->uploadDir . '/testdir');

        file_put_contents($pkgRoot . '/testfile.php', '<?php echo \'NEW\';');
        file_put_contents($this->rootDir . '/' . $this->uploadDir . '/testdir/testfile.php', '<?php echo \'OLD\';');

        $library->updateCode($package, $packageNew);

        $this->assertEquals('<?php echo \'OLD\';', file_get_contents($this->rootDir . '/' . $this->uploadDir . '/testdir/testfile.php'));
    }

    /**
     * Ensure user files get installed.
     */
    public function testFilesCopy()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'files' => array(
                    'templates' => 'templates'
                )
            )
        ));

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/templates';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        file_put_contents($pkgRoot . '/fe_page.html5', '<html>');

        $library->installCode($package);

        $this->assertFileEquals($pkgRoot . '/fe_page.html5', $this->rootDir . '/templates/fe_page.html5');
    }

    /**
     * Ensure that removing of files from package does not remove them from the file system when updating.
     */
    public function testFilesDoNotDelete()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'files' => array(
                    'templates' => 'templates'
                )
            )
        ));

        $packageNew = $this->createPackage(array());

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/templates';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        $this->ensureDirectoryExistsAndClear($this->rootDir . '/templates');

        file_put_contents($pkgRoot . '/fe_page.html5', '<html>');
        file_put_contents($this->rootDir . '/templates/fe_page.html5', '<html>');

        $library->updateCode($package, $packageNew);

        $this->assertFileEquals($pkgRoot . '/fe_page.html5', $this->rootDir . '/templates/fe_page.html5');
    }

    /**
     * Ensure that removing of files from package does not remove them from the file system when updating.
     */
    public function testFilesDoNotOverwrite()
    {
        $this->ensureDirectoryExistsAndClear($this->rootDir);

        $library = $this->mockInstaller();

        $package = $this->createPackage(array(
            'contao' => array(
                'files' => array(
                    'templates' => 'templates'
                )
            )
        ));

        $packageNew = $this->createPackage(array(
            'contao' => array(
                'files' => array(
                    'templates' => 'templates'
                )
            )
        ));

        $pkgRoot = $this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace/templates';
        $this->ensureDirectoryExistsAndClear($pkgRoot);
        $this->ensureDirectoryExistsAndClear($this->rootDir . '/templates');

        file_put_contents($pkgRoot . '/fe_page.html5', '<html> NEW');
        file_put_contents($this->rootDir . '/templates/fe_page.html5', '<html> OLD');

        $library->updateCode($package, $packageNew);

        $this->assertEquals('<html> OLD', file_get_contents($this->rootDir . '/templates/fe_page.html5'));
    }
}
