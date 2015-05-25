<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Environment;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Contao4Environment implements ContaoEnvironmentInterface
{
    private $rootDir;
    private $composer;
    private $version;
    private $build;
    private $uploadPath;

    public function __construct($rootDir, Composer $composer)
    {
        $this->rootDir  = $rootDir;
        $this->composer = $composer;
    }


    public function getRoot()
    {
        return $this->rootDir;
    }

    public function getVersion()
    {
        if (null === $this->version) {
            $this->version = $this->parseVersion('/(\d\.\d)\.\d/');
        }

        return $this->version;
    }

    public function getBuild()
    {
        if (null === $this->build) {
            $this->build = $this->parseVersion('/\d\.\d\.(\d)/');
        }

        return $this->build;
    }

    public function getFullVersion()
    {
        return $this->getVersion() . '.' . $this->getBuild();
    }

    public function getUploadPath()
    {
        if (null === $this->uploadPath) {
            $this->uploadPath = $this->executeCommand('debug:container --parameter=contao.upload_path');
        }

        return $this->uploadPath;
    }

    public function getSwiftMailerVersion()
    {
        throw new UnknownSwiftmailerException('SwiftMailer is included by Composer in Contao 4.');
    }

    private function getPackageVersion($packageName)
    {
        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepository   = $repositoryManager->getLocalRepository();

        /** @var PackageInterface $localPackage */
        foreach ($localRepository->getPackages() as $localPackage) {
            if ($localPackage->getName() == $packageName) {
                return $localPackage->getVersion();
            }
        }

        return null;
    }

    private function parseVersion($pattern)
    {
        $match   = null;
        $version = $this->getPackageVersion('contao/core-bundle');

        if (null === $version || !preg_match($pattern, $version, $match)) {
            throw new \RuntimeException('Contao Core version not found.');
        }

        return $match[1];
    }

    /**
     * Executes a command.
     *
     * @param string $cmd   The command
     *
     * @return string
     *
     * @throws \RuntimeException If the PHP executable cannot be found or the command cannot be executed
     */
    private static function executeCommand($cmd)
    {
        $phpFinder = new PhpExecutableFinder();

        if (false === ($phpPath = $phpFinder->find())) {
            throw new \RuntimeException('The php executable could not be found.');
        }

        $process = new Process(sprintf('%s app/console %s', $phpPath, $cmd));

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('An error occurred while executing the "' . $cmd . '" command.');
        }

        return $process->getOutput();
    }
}
