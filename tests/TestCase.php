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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * This is the testcase base class for all unit tests.
 */
class TestCase extends PHPUnitTestCase
{
    /**
     * The temp directory in use.
     *
     * @var string
     */
    protected $tempdir;

    /**
     * The file system instance to use.
     *
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        parent::setUp();

        $this->tempdir    = sys_get_temp_dir() . '/composer-plugin-test-' . substr(md5(mt_rand()), 0, 8);
        $this->filesystem = new Filesystem();

        $this->filesystem->ensureDirectoryExists($this->tempdir);

        $this->tempdir = realpath($this->tempdir);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown()
    {
        $this->filesystem->removeDirectory($this->tempdir);

        parent::tearDown();
    }
}
