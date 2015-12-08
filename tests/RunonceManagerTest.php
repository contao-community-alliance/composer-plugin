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

use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

class RunonceManagerTest extends \PHPUnit_Framework_TestCase
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
    }

    protected function tearDown()
    {
        $this->filesystem->removeDirectory($this->tempdir);
    }

    public function testDumpsNothingIfEmpty()
    {
        $file = $this->tempdir . '/runonce.php';

        $manager = new RunonceManager($file);
        $manager->dump();

        $this->assertFalse(file_exists($file));
    }

    public function testDumpsNothingIfFileDoesNotExist()
    {
        $file = $this->tempdir . '/runonce.php';

        $manager = new RunonceManager($file);
        $manager->addFile('/foo/bar/update.php');
        $manager->dump();

        $this->assertFalse(is_file($file));
    }

    public function testDumpsToFile()
    {
        $file = $this->tempdir . '/runonce.php';

        touch($this->tempdir . '/test.php');

        $manager = new RunonceManager($file);
        $manager->addFile($this->tempdir . '/test.php');
        $manager->dump();

        $this->assertTrue(is_file($file));
        $this->assertNotFalse(strpos(file_get_contents($file), $this->tempdir . '/test.php'));
    }

    /**
     * @runInSeparateProcess
     * @expectedException \RuntimeException
     */
    public function testDumpThrowsExceptionIfFileIsNotWritable()
    {
        include __DIR__ . '/fixtures/mock_is_writable.php';

        $file    = $this->tempdir . '/runonce.php';
        $runonce = $this->tempdir . '/test.php';

        touch($file);
        touch($runonce);

        $manager = new RunonceManager($file);
        $manager->addFile($runonce);
        $manager->dump();
    }

    /**
     * @runInSeparateProcess
     * @expectedException \RuntimeException
     */
    public function testDumpThrowsExceptionIfTargetExistsAndIsNotAFile()
    {
        $file    = $this->tempdir . '/runonce.php';
        $runonce = $this->tempdir . '/test.php';

        $this->filesystem->ensureDirectoryExists($file);
        touch($runonce);

        $manager = new RunonceManager($file);
        $manager->addFile($runonce);
        $manager->dump();
    }

    /**
     * @runInSeparateProcess
     * @expectedException \RuntimeException
     */
    public function testDumpThrowsExceptionIfWriteFails()
    {
        include __DIR__ . '/fixtures/mock_file_put_contents.php';

        $file    = $this->tempdir . '/runonce.php';
        $runonce = $this->tempdir . '/test.php';

        touch($runonce);

        $manager = new RunonceManager($file);
        $manager->addFile($runonce);
        $manager->dump();
    }

    public function testRenamedExisting()
    {
        $file    = $this->tempdir . '/runonce.php';
        $runonce = $this->tempdir . '/test.php';

        touch($file);
        touch($runonce);

        $fs = $this->getMock('Composer\\Util\\Filesystem', ['rename']);
        $fs
            ->expects($this->once())
            ->method('rename')
            ->with($file)
        ;

        $manager = new RunonceManager($file, $fs);
        $manager->addFile($runonce);
        $manager->dump();

        $this->assertTrue(is_file($file));
        $this->assertNotFalse(strpos(file_get_contents($file), $this->tempdir . '/runonce_'));
        $this->assertNotFalse(strpos(file_get_contents($file), $runonce));
    }
}
