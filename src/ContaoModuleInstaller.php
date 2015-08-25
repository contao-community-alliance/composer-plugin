<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class ContaoModuleInstaller extends LibraryInstaller
{
    /**
     * Add symlinks for Contao sources after installing a package.
     *
     * {@inheritdoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $this->addSymlinks($package, $this->getSources($package));
    }

    /**
     * Remove symlinks for Contao sources before update, then add them again afterwards.
     *
     * {@inheritdoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->removeSymlinks($initial, $this->getSources($initial));

        parent::update($repo, $initial, $target);

        $this->addSymlinks($target, $this->getSources($target));
    }

    /**
     * Remove symlinks for Contao sources before uninstalling a package.
     *
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->removeSymlinks($package, $this->getSources($package));

        parent::uninstall($repo, $package);
    }

    /**
     * Gets installation files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getSources(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'sources') ?: [];
    }

    /**
     * Gets user files (TL_FILES) from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getUserFiles(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'userfiles') ?: [];
    }

    /**
     * Gets runonce files from the Contao package.
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    protected function getRunonces(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'runonce') ?: [];
    }

    /**
     * Retrieves a value from the package extra "contao" section.
     *
     * @param PackageInterface $package
     * @param string           $key
     *
     * @return mixed|null
     */
    private function getContaoExtra(PackageInterface $package, $key)
    {
        $extras = $package->getExtra();

        if (!isset($extras['contao']) || !isset($extras['contao'][$key])) {
            return null;
        }

        return $extras['contao'][$key];
    }

    /**
     * Creates symlinks for a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package
     * @param array            $map
     */
    private function addSymlinks(PackageInterface $package, array $map)
    {
        if (empty($map)) {
            return;
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Installing Contao sources for %s', $package->getName()));
        }

        $packageRoot = $this->getPackageBasePath($package);
        $contaoRoot  = $this->getContaoRoot();
        $actions     = [];

        // Check the file map first and make sure nothing exists.
        foreach ($map as $source => $target) {
            $sourcePath = $this->filesystem->normalizePath($packageRoot . ($source ? ('/'.$source) : ''));
            $targetPath = $this->filesystem->normalizePath($contaoRoot . '/' . $target);

            if (!is_readable($sourcePath)) {
                throw new \RuntimeException(
                    sprintf('Installation source "%s" does not exist or is not readable', $source)
                );
            }

            if (file_exists($targetPath)) {
                // Target link already exists and is correct, do nothing
                if (is_link($targetPath) && $sourcePath === readlink($targetPath)) {
                    continue;
                }

                throw new \RuntimeException(sprintf('Installation target "%s" already exists', $source));
            }

            $actions[$sourcePath] = $targetPath;
        }

        // Only actually create the links if the checks are successful to prevent orphans.
        foreach ($actions as $source => $target) {
            if ($this->io->isVeryVerbose()) {
                $this->io->writeError(sprintf('  - Linking "%s" to "%s"', $source, $target));
            }

            $this->filesystem->ensureDirectoryExists(dirname($target));

            symlink($source, $target);
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError('');
        }
    }

    /**
     * Remove symlinks from a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package
     * @param array            $map
     */
    private function removeSymlinks(PackageInterface $package, array $map)
    {
        if (empty($map)) {
            return;
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Removing Contao sources for %s', $package->getName()));
        }

        $packageRoot = $this->getPackageBasePath($package);
        $contaoRoot  = $this->getContaoRoot();
        $actions     = [];

        // Check the file map first and make sure we only remove our own symlinks.
        foreach ($map as $source => $target) {
            $sourcePath = $this->filesystem->normalizePath($packageRoot . ($source ? ('/'.$source) : ''));
            $targetPath = $this->filesystem->normalizePath($contaoRoot . '/' . $target);

            if (!file_exists($targetPath)) {
                continue;
            }

            if (!is_link($targetPath) || $sourcePath !== readlink($targetPath)) {
                throw new \RuntimeException(sprintf('"%s" is not a link to "%s"', $source, $target));
            }

            $actions[] = $targetPath;
        }

        // Remove the symlinks if everything is ok.
        foreach ($actions as $target) {
            if ($this->io->isVeryVerbose()) {
                $this->io->writeError(sprintf('  - Removing "%s"', $target));
            }

            $this->filesystem->unlink($target);

            $this->removeEmptyDirectories(dirname($target));
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError('');
        }
    }

    /**
     * Clean up empty directories.
     *
     * @param string $pathname
     *
     * @return bool
     */
    public function removeEmptyDirectories($pathname)
    {
        if (is_dir($pathname)
            && $pathname !== $this->getContaoRoot()
            && $this->filesystem->isDirEmpty($pathname)
        ) {
            if (!$this->removeEmptyDirectories(dirname($pathname))) {
                if ($this->io->isVeryVerbose()) {
                    $this->io->writeError(sprintf('  - Removing empty directory "%s"', $pathname));
                }

                $this->filesystem->removeDirectory($pathname);
            }

            return true;
        }

        return false;
    }

    /**
     * Gets the Contao root (parent folder of vendor dir).
     *
     * @return string
     */
    private function getContaoRoot()
    {
        $this->initializeVendorDir();

        return dirname($this->vendorDir);
    }
}
