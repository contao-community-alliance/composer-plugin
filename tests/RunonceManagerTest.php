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
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;
use Composer\Util\Filesystem;

/**
 * This tests the RunonceManager.
 */
class RunonceManagerTest extends TestCase
{
    /**
     * Test that nothing gets dumped when the file list is empty.
     *
     * @return void
     */
    public function testDumpsNothingIfEmpty()
    {
        $file = $this->tempdir . '/runonce.php';

        $manager = new RunonceManager($file);
        $manager->dump();

        $this->assertFalse(file_exists($file));
    }

    /**
     * Test that nothing gets dumped when the source file does not exist.
     *
     * @return void
     */
    public function testDumpsNothingIfFileDoesNotExist()
    {
        $file = $this->tempdir . '/runonce.php';

        $manager = new RunonceManager($file);
        $manager->addFile('/foo/bar/update.php');
        $manager->dump();

        $this->assertFalse(is_file($file));
    }

    /**
     * Test that the runonce gets dumped correctly.
     *
     * @return void
     */
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
     * Test that an exception is thrown when the source file is not readable.
     *
     * @runInSeparateProcess
     *
     * @expectedException \RuntimeException
     *
     * @return void
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
     * Test that an exception is thrown when the target path exists and is not a file.
     *
     * @runInSeparateProcess
     *
     * @expectedException \RuntimeException
     *
     * @return void
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
     * Test that an exception is thrown when the target file can not be written to.
     *
     * @runInSeparateProcess
     *
     * @expectedException \RuntimeException
     *
     * @return void
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

    /**
     * Test that an existing runonce file gets renamed.
     *
     * @return void
     */
    public function testRenamedExisting()
    {
        $file    = $this->tempdir . '/runonce.php';
        $runonce = $this->tempdir . '/test.php';

        touch($file);
        touch($runonce);

        $fileSystem = $this->getMockBuilder(Filesystem::class)->setMethods(['rename'])->getMock();
        $fileSystem
            ->expects($this->once())
            ->method('rename')
            ->with($file);

        $manager = new RunonceManager($file, $fileSystem);
        $manager->addFile($runonce);
        $manager->dump();

        $this->assertTrue(is_file($file));
        $this->assertNotFalse(strpos(file_get_contents($file), $this->tempdir . '/runonce_'));
        $this->assertNotFalse(strpos(file_get_contents($file), $runonce));
    }

    /**
     * Test that the parent directory gets created if it does not exist.
     *
     * @return void
     */
    public function testCreatesMissingParentDirectories()
    {
        $file = $this->tempdir . '/test/runonce.php';

        touch($this->tempdir . '/test.php');

        $manager = new RunonceManager($file);
        $manager->addFile($this->tempdir . '/test.php');
        $manager->dump();

        $this->assertTrue(is_file($file));
    }
}
