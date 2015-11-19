<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class UserFilesLocator
{
    /**
     * @var string
     */
    private $rootDir;

    /**
     * Constructor.
     *
     * @param string $rootDir
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
     * @throws \UnderflowException if console application was not found
     */
    private function getConsolePath()
    {
        if (file_exists($this->rootDir . '/app/console')) {
            return $this->rootDir . '/app/console';
        }

        $finder = new Finder();
        $files = $finder->files()->depth(1)->name('console')->in($this->rootDir);

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
     * @throws ProcessFailedException if the console execution failed
     */
    private function getPathFromConsole()
    {
        $console = new Process($this->getConsolePath() . ' debug:container --parameter=contao.upload_path');
        $console->mustRun();

        return $console->getOutput();
    }
}
