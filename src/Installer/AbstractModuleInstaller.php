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
 * @author     Kamil Kuzminski <kamil.kuzminski@codefog.pl>
 * @author     M. Vondano <moritz.vondano@gmail.com>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @copyright  2013-2016 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Installer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;
use ContaoCommunityAlliance\Composer\Plugin\UserFilesLocator;
use React\Promise\PromiseInterface;

/**
 * AbstractModuleInstaller is the parent class that handles file copying and symlinking.
 */
abstract class AbstractModuleInstaller extends LibraryInstaller
{
    const DUPLICATE_IGNORE    = 1;
    const DUPLICATE_OVERWRITE = 2;
    const DUPLICATE_FAIL      = 3;

    const INVALID_IGNORE    = 1;
    const INVALID_OVERWRITE = 2;
    const INVALID_FAIL      = 3;

    /**
     * The run once manager in use.
     *
     * @var RunonceManager
     */
    protected $runonceManager;

    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager The run once manager to use.
     *
     * @param IOInterface    $inputOutput    The input/output abstraction to use.
     *
     * @param Composer       $composer       The composer instance.
     *
     * @param string         $type           The typename this installer is responsible for.
     *
     * @param Filesystem     $filesystem     The file system instance.
     */
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $inputOutput,
        Composer $composer,
        $type,
        Filesystem $filesystem = null
    ) {
        parent::__construct($inputOutput, $composer, $type, $filesystem);

        $this->runonceManager = $runonceManager;
    }

    /**
     * Make sure symlinks/directories exist, otherwise consider a package uninstalled
     * so they are being regenerated.
     *
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (false === parent::isInstalled($repo, $package)) {
            return false;
        }

        $targetRoot = $this->getContaoRoot();

        foreach ($this->getSources($package) as $targetPath) {
            if (!file_exists($this->filesystem->normalizePath($targetRoot . '/' . $targetPath))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add symlinks for Contao sources after installing a package.
     *
     * {@inheritdoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $install = function () use ($package) {
            $this->io->write('  - Installing Contao sources for '.$package->getName(), true, IOInterface::VERBOSE);
            $this->addSymlinks($package, $this->getContaoRoot(), $this->getSources($package));
            $this->addCopies($package, $this->getFilesRoot(), $this->getUserFiles($package), self::DUPLICATE_IGNORE);
            $this->addRunonces($package, $this->getRunonces($package));
        };

        $promise = parent::install($repo, $package);

        if ($promise instanceof PromiseInterface) {
            return $promise->then($install);
        }

        $install();

        return null;
    }

    /**
     * Remove symlinks for Contao sources before update, then add them again afterwards.
     *
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException When the requested package is not installed.
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (!$repo->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        $contaoRoot = $this->getContaoRoot();

        $this->removeSymlinks($initial, $contaoRoot, $this->getSources($initial));

        $update = function () use ($contaoRoot, $initial, $target) {
            $this->io->write('  - Updating Contao sources for '.$initial->getName(), true, IOInterface::VERBOSE);
            $this->addSymlinks($target, $contaoRoot, $this->getSources($target));
            $this->addCopies($target, $this->getFilesRoot(), $this->getUserFiles($target), self::DUPLICATE_IGNORE);
            $this->addRunonces($target, $this->getRunonces($target));
        };

        $promise = parent::update($repo, $initial, $target);

        if ($promise instanceof PromiseInterface) {
            return $promise->then($update);
        }

        $update();

        return null;
    }

    /**
     * Remove symlinks for Contao sources before uninstalling a package.
     *
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException When the requested package is not installed.
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $this->io->write('  - Removing Contao sources for '.$package->getName(), true, IOInterface::VERBOSE);
        $this->removeSymlinks($package, $this->getContaoRoot(), $this->getSources($package));

        return parent::uninstall($repo, $package);
    }

    /**
     * Gets installation files from the Contao package.
     *
     * @param PackageInterface $package The package to extract the sources from.
     *
     * @return array
     */
    abstract protected function getSources(PackageInterface $package);

    /**
     * Gets user files (TL_FILES) from the Contao package.
     *
     * @param PackageInterface $package The package to extract the user files from.
     *
     * @return array
     */
    abstract protected function getUserFiles(PackageInterface $package);

    /**
     * Gets runonce files from the Contao package.
     *
     * @param PackageInterface $package The package to extract the run once files from.
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
     * Gets the user files root folder (e.g. TL_ROOT/files).
     *
     * @return string
     */
    protected function getFilesRoot()
    {
        $locator = new UserFilesLocator($this->getContaoRoot());

        return $locator->locate();
    }

    /**
     * Creates symlinks for a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package    The package being processed.
     * @param string           $targetRoot The target directory.
     * @param array            $pathMap    The path mapping.
     * @param int              $mode       The mode how to handle duplicate files.
     *
     * @return void
     *
     * @throws \RuntimeException When the symlink could not be created.
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

            if ($this->canAddSymlink($source, $target, $mode)) {
                $actions[$source] = $target;
            }
        }

        // Only actually create the links if the checks are successful to prevent orphans.
        foreach ($actions as $source => $target) {
            $this->io->write(sprintf('  - Linking "%s" to "%s"', $source, $target), true, IOInterface::VERY_VERBOSE);

            $this->filesystem->ensureDirectoryExists(dirname($target));

            if (Platform::isWindows()) {
                // @codingStandardsIgnoreStart
                $success = @symlink($source, $target);
                // @codingStandardsIgnoreEnd
            } else {
                $success = $this->filesystem->relativeSymlink($source, $target);
            }

            if (!$success) {
                throw new \RuntimeException('Failed to create symlink ' . $target);
            }
        }
    }

    /**
     * Removes symlinks from a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package    The package being processed.
     * @param string           $targetRoot The target directory.
     * @param array            $pathMap    The path mapping.
     * @param int              $mode       The mode how to handle duplicate files.
     *
     * @return void
     */
    protected function removeSymlinks(
        PackageInterface $package,
        $targetRoot,
        array $pathMap,
        $mode = self::INVALID_FAIL
    ) {
        if (empty($pathMap)) {
            return;
        }

        $packageRoot = $this->getInstallPath($package);
        $actions     = [];

        // Check the file map first and make sure we only remove our own symlinks.
        foreach ($pathMap as $sourcePath => $targetPath) {
            $source = $this->filesystem->normalizePath($packageRoot . ($sourcePath ? ('/'.$sourcePath) : ''));
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if ($this->canRemoveSymlink($source, $target, $mode)) {
                $actions[] = $target;
            }
        }

        // Remove the symlinks if everything is ok.
        foreach ($actions as $target) {
            $this->io->write(sprintf('  - Removing "%s"', $target), true, IOInterface::VERY_VERBOSE);

            if (is_dir($target)) {
                $this->filesystem->removeDirectory($target);
            } else {
                $this->filesystem->unlink($target);
            }

            $this->removeEmptyDirectories(dirname($target), $targetRoot);
        }
    }

    /**
     * Creates copies for a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param PackageInterface $package    The package being processed.
     * @param string           $targetRoot The target directory.
     * @param array            $pathMap    The path mapping.
     * @param int              $mode       The mode how to handle duplicate files.
     *
     * @return void
     *
     * @throws \RuntimeException When a source path does not exist or is not readable.
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
            $this->io->write(sprintf('  - Copying "%s" to "%s"', $source, $target), true, IOInterface::VERY_VERBOSE);
            $this->copyRecursive($source, $target);
        }
    }

    /**
     * Removes copies from a map of relative file paths.
     * Key is the relative path to composer package, whereas "value" is relative to Contao root.
     *
     * @param string $targetRoot The target directory.
     *
     * @param array  $pathMap    The path mapping.
     *
     * @return void
     */
    protected function removeCopies($targetRoot, array $pathMap)
    {
        if (empty($pathMap)) {
            return;
        }

        $actions = [];

        // Check the file map first and make sure we only remove our own symlinks.
        foreach ($pathMap as $targetPath) {
            $target = $this->filesystem->normalizePath($targetRoot . '/' . $targetPath);

            if (!file_exists($target)) {
                continue;
            }

            $actions[] = $target;
        }

        // Remove the symlinks if everything is ok.
        foreach ($actions as $target) {
            $this->io->write(sprintf('  - Removing "%s"', $target), true, IOInterface::VERY_VERBOSE);

            $this->filesystem->unlink($target);

            $this->removeEmptyDirectories(dirname($target), $targetRoot);
        }
    }

    /**
     * Adds runonce files of a package to the RunonceManager instance.
     *
     * @param PackageInterface $package The package being processed.
     * @param array            $files   The file names of all runonce files.
     *
     * @return void
     */
    protected function addRunonces(PackageInterface $package, array $files)
    {
        $rootDir = $this->getInstallPath($package);

        foreach ($files as $file) {
            $this->runonceManager->addFile($this->filesystem->normalizePath($rootDir . '/' . $file));
        }
    }

    /**
     * Recursive copy source file or directory to target path.
     *
     * @param string $source The source file or folder.
     * @param string $target The target file or folder.
     *
     * @return void
     */
    private function copyRecursive($source, $target)
    {
        if (!is_dir($source)) {
            $this->filesystem->ensureDirectoryExists(dirname($target));
            copy($source, $target);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $this->filesystem->ensureDirectoryExists($target);

        foreach ($iterator as $file) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($file->isDir()) {
                $this->filesystem->ensureDirectoryExists($targetPath);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Clean up empty directories.
     *
     * @param string $pathname The path to remove if empty.
     * @param string $root     The path of the root installation.
     *
     * @return bool
     */
    private function removeEmptyDirectories($pathname, $root)
    {
        if (is_dir($pathname)
            && $pathname !== $root
            && $this->filesystem->isDirEmpty($pathname)
        ) {
            $this->filesystem->removeDirectory($pathname);

            if (!$this->removeEmptyDirectories(dirname($pathname), $root)) {
                $this->io->write(sprintf('  - Removing empty directory "%s"', $pathname), IOInterface::VERY_VERBOSE);
            }

            return true;
        }

        return false;
    }

    /**
     * Check if the source exists, is readable and shall get symlink'ed to the target.
     *
     * @param string $source The source path.
     * @param string $target The target path.
     * @param int    $mode   The duplicate file handling mode.
     *
     * @return bool
     *
     * @throws \RuntimeException When the source is not readable.
     */
    private function canAddSymlink($source, $target, $mode)
    {
        if (!is_readable($source)) {
            throw new \RuntimeException(
                sprintf('Installation source "%s" does not exist or is not readable', $source)
            );
        }

        $realSource = realpath($source) ?: $source;
        $realTarget = realpath($target) ?: $target;

        if (file_exists($target)) {
            // Target link already exists and is correct, do nothing
            if (is_link($target)
                && $this->filesystem->normalizePath($realSource) === $this->filesystem->normalizePath($realTarget)
            ) {
                return false;
            }

            if (!$this->canAddTarget($target, $mode)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the target exists, is a symlink and the symlink points to the target and therefore shall get removed.
     *
     * @param string $source The source path.
     *
     * @param string $target The target path.
     *
     * @param int    $mode   The invalid file handling mode.
     *
     * @return bool
     *
     * @throws \RuntimeException When a file entry is not a symlink to the expected target and mode is INVALID_FAIL.
     */
    private function canRemoveSymlink($source, $target, $mode)
    {
        if (!file_exists($target)) {
            return false;
        }

        $realSource = realpath($source) ?: $source;
        $realTarget = realpath($target) ?: $target;

        if (!is_link($target)
            || $this->filesystem->normalizePath($realSource) !== $this->filesystem->normalizePath($realTarget)
        ) {
            if (self::INVALID_IGNORE === $mode) {
                return false;
            }

            if (self::INVALID_FAIL === $mode) {
                throw new \RuntimeException(
                    sprintf(
                        '"%s" is not a link to "%s" (expected "%s" but got "%s")',
                        $target,
                        $source,
                        $this->filesystem->normalizePath($realSource),
                        $this->filesystem->normalizePath($realTarget)
                    )
                );
            }
        }

        return true;
    }

    /**
     * Checks if the target file should be added based on the given mode.
     *
     * @param string $target The target path.
     *
     * @param int    $mode   The overwrite mode.
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
}
