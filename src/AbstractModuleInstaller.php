<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;

abstract class AbstractModuleInstaller extends LibraryInstaller
{
    const DUPLICATE_IGNORE = 1;
    const DUPLICATE_OVERWRITE = 2;
    const DUPLICATE_FAIL = 3;

    const INVALID_IGNORE = 1;
    const INVALID_OVERWRITE = 2;
    const INVALID_FAIL = 3;

    /**
     * @var RunonceManager
     */
    protected $runonceManager;

    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager
     * @param IOInterface    $io
     * @param Composer       $composer
     * @param string         $type
     * @param Filesystem     $filesystem
     */
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $io,
        Composer $composer,
        $type,
        Filesystem $filesystem = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->runonceManager = $runonceManager;
    }


    /**
     * Add symlinks for Contao sources after installing a package.
     *
     * {@inheritdoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Installing Contao sources for %s', $package->getName()));
        }

        parent::install($repo, $package);

        $contaoRoot = $this->getContaoRoot();

        $this->addSymlinks($package, $contaoRoot, $this->getSources($package));
        $this->addRunonces($package, $this->getRunonces($package));

        if ($this->io->isVerbose()) {
            $this->io->writeError('');
        }
    }

    /**
     * Remove symlinks for Contao sources before update, then add them again afterwards.
     *
     * {@inheritdoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (!$repo->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Updating Contao sources for %s', $initial->getName()));
        }

        $contaoRoot = $this->getContaoRoot();

        $this->removeSymlinks($initial, $contaoRoot, $this->getSources($initial));

        parent::update($repo, $initial, $target);

        $this->addSymlinks($target, $contaoRoot, $this->getSources($target));
        $this->addRunonces($target, $this->getRunonces($target));

        if ($this->io->isVerbose()) {
            $this->io->writeError('');
        }
    }

    /**
     * Remove symlinks for Contao sources before uninstalling a package.
     *
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Removing Contao sources for %s', $package->getName()));
        }

        $contaoRoot = $this->getContaoRoot();

        $this->removeSymlinks($package, $contaoRoot, $this->getSources($package));

        parent::uninstall($repo, $package);

        if ($this->io->isVerbose()) {
            $this->io->writeError('');
        }
    }

    /**
     * Gets installation files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    abstract protected function getSources(PackageInterface $package);

    /**
     * Gets user files (TL_FILES) from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    abstract protected function getUserFiles(PackageInterface $package);

    /**
     * Gets runonce files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    abstract protected function getRunonces(PackageInterface $package);

    /**
     * Gets the Contao root (parent folder of vendor dir).
     *
     * @return string
     */
    protected function getContaoRoot()
    {
        $this->initializeVendorDir();

        return dirname($this->vendorDir);
    }

    /**
     * Creates symlinks for a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package
     * @param string           $targetRoot
     * @param array            $pathMap
     * @param int              $mode
     */
    protected function addSymlinks(PackageInterface $package, $targetRoot, array $pathMap, $mode = self::DUPLICATE_FAIL)
    {
        if (empty($pathMap)) {
            return;
        }

        $packageRoot = $this->getInstallPath($package);
        $actions     = [];

        // Check the file map first and make sure nothing exists.
        foreach ($pathMap as $sourcePath => $targetPath) {
            $source = $this->filesystem->normalizePath($packageRoot . ($sourcePath ? ('/'.$sourcePath) : ''));
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if (!is_readable($source)) {
                throw new \RuntimeException(
                    sprintf('Installation source "%s" does not exist or is not readable', $sourcePath)
                );
            }

            if (file_exists($target)) {
                // Target link already exists and is correct, do nothing
                if (is_link($target) && $source === readlink($target)) {
                    continue;
                }

                if (!$this->canAddTarget($target, $mode)) {
                    continue;
                }
            }

            $actions[$source] = $target;
        }

        // Only actually create the links if the checks are successful to prevent orphans.
        foreach ($actions as $source => $target) {
            $this->logSymlink($source, $target);

            $this->filesystem->ensureDirectoryExists(dirname($target));

            symlink($source, $target);
        }
    }

    /**
     * Removes symlinks from a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package
     * @param string           $targetRoot
     * @param array            $pathMap
     * @param int              $mode
     */
    protected function removeSymlinks(PackageInterface $package, $targetRoot, array $pathMap, $mode = self::INVALID_FAIL)
    {
        if (empty($pathMap)) {
            return;
        }

        $packageRoot = $this->getInstallPath($package);
        $actions     = [];

        // Check the file map first and make sure we only remove our own symlinks.
        foreach ($pathMap as $sourcePath => $targetPath) {
            $source = $this->filesystem->normalizePath($packageRoot . ($sourcePath ? ('/'.$sourcePath) : ''));
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if (!file_exists($target)) {
                continue;
            }

            if (!is_link($target) || $source !== readlink($target)) {
                if (self::INVALID_IGNORE === $mode) {
                    continue;
                }

                if (self::INVALID_FAIL === $mode) {
                    throw new \RuntimeException(sprintf('"%s" is not a link to "%s"', $sourcePath, $targetPath));
                }
            }

            $actions[] = $target;
        }

        // Remove the symlinks if everything is ok.
        foreach ($actions as $target) {
            $this->logRemove($target);

            $this->filesystem->unlink($target);

            $this->removeEmptyDirectories(dirname($target));
        }
    }

    /**
     * Creates copies for a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package
     * @param string           $targetRoot
     * @param array            $pathMap
     * @param int              $mode
     */
    protected function addCopies(PackageInterface $package, $targetRoot, array $pathMap, $mode = self::DUPLICATE_FAIL)
    {
        if (empty($pathMap)) {
            return;
        }

        $packageRoot = $this->getInstallPath($package);
        $actions     = [];

        // Check the file map first and make sure nothing exists.
        foreach ($pathMap as $sourcePath => $targetPath) {
            $source = $this->filesystem->normalizePath($packageRoot . (empty($sourcePath) ? '' : ('/'.$sourcePath)));
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if (!is_readable($source)) {
                throw new \RuntimeException(
                    sprintf('Installation source "%s" does not exist', $sourcePath)
                );
            }

            if (file_exists($target) && !$this->canAddTarget($target, $mode)) {
                continue;
            }

            $actions[$source] = $target;
        }

        // Only actually create the links if the checks are successful to prevent orphans.
        foreach ($actions as $source => $target) {
            $this->logCopy($source, $target);

            $this->filesystem->ensureDirectoryExists(dirname($target));

            copy($source, $target);
        }
    }

    /**
     * Removes copies from a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param string           $targetRoot
     * @param array            $pathMap
     */
    protected function removeCopies($targetRoot, array $pathMap)
    {
        if (empty($pathMap)) {
            return;
        }

        $actions     = [];

        // Check the file map first and make sure we only remove our own symlinks.
        foreach ($pathMap as $sourcePath => $targetPath) {
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if (!file_exists($target)) {
                continue;
            }

            $actions[] = $target;
        }

        // Remove the symlinks if everything is ok.
        foreach ($actions as $target) {
            $this->logRemove($target);

            $this->filesystem->unlink($target);

            $this->removeEmptyDirectories(dirname($target));
        }
    }

    /**
     * Adds runonce files of a package to the RunonceManager instance.
     *
     * @param PackageInterface $package
     * @param array            $files
     */
    protected function addRunonces(PackageInterface $package, array $files)
    {
        $rootDir = $this->getInstallPath($package);

        foreach ($files as $file) {
            $this->runonceManager->addFile($this->filesystem->normalizePath($rootDir . '/' . $file));
        }
    }

    /**
     * Clean up empty directories.
     *
     * @param string $pathname
     *
     * @return bool
     */
    private function removeEmptyDirectories($pathname)
    {
        if (is_dir($pathname)
            && $pathname !== $this->getContaoRoot()
            && $this->filesystem->isDirEmpty($pathname)
        ) {
            $this->filesystem->removeDirectory($pathname);

            if (!$this->removeEmptyDirectories(dirname($pathname))) {
                if ($this->io->isVeryVerbose()) {
                    $this->io->writeError(sprintf('  - Removing empty directory "%s"', $pathname));
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Checks if the target file should be added based on the given mode.
     *
     * @param string $target
     * @param int    $mode
     *
     * @return bool
     *
     * @throws \RuntimeException If target exists and can not or must not be removed.
     */
    private function canAddTarget($target, $mode)
    {
        // Mode is set to ignore existing targets
        if ($mode === self::DUPLICATE_IGNORE) {
            return false;
        }

        // Error if we're not allowed to overwrite or can't remove the existing target
        if ($mode !== self::DUPLICATE_OVERWRITE || !$this->filesystem->remove($target)) {
            throw new \RuntimeException(sprintf('Installation target "%s" already exists', $target));
        }

        return true;
    }

    private function logSymlink($source, $target)
    {
        if ($this->io->isVeryVerbose()) {
            $this->io->writeError(sprintf('  - Linking "%s" to "%s"', $source, $target));
        }
    }

    private function logCopy($source, $target)
    {
        if ($this->io->isVeryVerbose()) {
            $this->io->writeError(sprintf('  - Copying "%s" to "%s"', $source, $target));
        }
    }

    private function logRemove($target)
    {
        if ($this->io->isVeryVerbose()) {
            $this->io->writeError(sprintf('  - Removing "%s"', $target));
        }
    }
}
