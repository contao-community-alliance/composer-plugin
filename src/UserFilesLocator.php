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

namespace ContaoCommunityAlliance\Composer\Plugin;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * UserFilesLocator finds the path to the user upload folder.
 * It tries to run the Symfony console and if unsuccessfull falls back to the default (/files).
 */
class UserFilesLocator
{
    /**
     * The root directory to scan within.
     *
     * @var string
     */
    private $rootDir;

    /**
     * Constructor.
     *
     * @param string $rootDir The root directory to scan within.
     */
    public function __construct($rootDir)
    {
        $this->rootDir = $rootDir;
    }

    /**
     * Locates the user files dir.
     *
     * @return string
     */
    public function locate()
    {
        try {
            return $this->rootDir . '/' . $this->getPathFromConsole();
        } catch (\Exception $e) {
            return $this->rootDir . '/files';
        }
    }

    /**
     * Find path to console application
     *
     * @return string
     *
     * @throws \UnderflowException If console application was not found.
     */
    private function getConsolePath()
    {
        if (file_exists($this->rootDir . '/app/console')) {
            return $this->rootDir . '/app/console';
        }

        $finder = new Finder();
        $files  = $finder->files()->depth(1)->name('console')->in($this->rootDir);

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            return $file->getPathname();
        }

        throw new \UnderflowException('Symfony console application was not found.');
    }

    /**
     * Dumps container information to get the upload path.
     *
     * @return string
     *
     * @throws ProcessFailedException If the console execution failed.
     */
    private function getPathFromConsole()
    {
        $arguments = [$this->getConsolePath(), 'debug:container', '--parameter=contao.upload_path'];

        // Backwards compatibility with symfony/process < 3.3 (see #87)
        if (method_exists(Process::class, 'setCommandline')) {
            $arguments = implode(' ', array_map('escapeshellarg', $arguments));
        }

        $console = new Process($arguments);
        $console->mustRun();

        return $console->getOutput();
    }
}
