<?php

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

        $this->filesystem->emptyDirectory($this->tempdir);
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

    public function testRenamedExisting()
    {
        $file = $this->tempdir . '/runonce.php';

        touch($this->tempdir . '/runonce.php');
        touch($this->tempdir . '/test.php');

        $fs = $this->getMock('Composer\\Util\\Filesystem', ['rename']);
        $fs
            ->expects($this->once())
            ->method('rename')
            ->with($this->tempdir . '/runonce.php')
        ;

        $manager = new RunonceManager($file, $fs);
        $manager->addFile($this->tempdir . '/test.php');
        $manager->dump();

        $this->assertTrue(is_file($file));
        $this->assertNotFalse(strpos(file_get_contents($file), $this->tempdir . '/runonce_'));
        $this->assertNotFalse(strpos(file_get_contents($file), $this->tempdir . '/test.php'));
    }
}
