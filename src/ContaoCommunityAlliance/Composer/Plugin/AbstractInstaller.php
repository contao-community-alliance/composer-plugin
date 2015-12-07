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
 * @author     Dominik Zogg <dominik.zogg@gmail.com>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Oliver Hoff <oliver@hofff.com>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use SplFileInfo;

/**
 * Basic installer that install Contao extensions.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
abstract class AbstractInstaller extends LibraryInstaller
{
    /**
     * Module type of contao packages.
     */
    const MODULE_TYPE = 'contao-module';

    /**
     * Module type of converted ER2 contao packages.
     */
    const LEGACY_MODULE_TYPE = 'legacy-contao-module';

    /**
     * The plugin instance.
     *
     * @var Plugin
     */
    protected $plugin;

    /**
     * Create a new instance.
     *
     * @param IOInterface $inputOutput The input output interface to use.
     *
     * @param Composer    $composer    The composer instance.
     *
     * @param Plugin      $plugin      The plugin instance.
     */
    public function __construct(IOInterface $inputOutput, Composer $composer, $plugin)
    {
        parent::__construct($inputOutput, $composer);
        $this->plugin = $plugin;
    }

    /**
     * Writes a message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string.
     *
     * @param bool         $newline  Whether to add a newline or not.
     *
     * @return void
     */
    public function write($messages, $newline = true)
    {
        $this->io->write($messages, $newline);
    }

    /**
     * Writes a message to the output.
     *
     * @param string|array $messages The message as an array of lines or a single string.
     *
     * @param bool         $newline  Whether to add a newline or not.
     *
     * @return void
     */
    public function writeVerbose($messages, $newline = true)
    {
        if ($this->io->isVerbose()) {
            $this->io->write($messages, $newline);
        }
    }

    /**
     * Strip the prefix from the given path.
     *
     * @param string $prefix The prefix to strip.
     *
     * @param string $path   The path from where the prefix shall get stripped.
     *
     * @return string
     */
    public static function unprefixPath($prefix, $path)
    {
        $len = strlen($prefix);
        if (!$len || $len > strlen($path)) {
            return $path;
        }
        $prefix = self::getNativePath($prefix);
        $match  = self::getNativePath(substr($path, 0, $len));
        if ($prefix == $match) {
            return substr($path, $len);
        }
        return $path;
    }

    /**
     * Translate a path with mixed slash and backslash occurrences to a string containing only the passed separator.
     *
     * @param string $path The path.
     *
     * @param string $sep  The desired directory separator (optional, defaults to current OS default).
     *
     * @return mixed
     */
    public static function getNativePath($path, $sep = DIRECTORY_SEPARATOR)
    {
        return str_replace(array('/', '\\'), $sep, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function installCode(PackageInterface $package)
    {
        $map = $this->mapSources($package);
        parent::installCode($package);
        $this->updateSources($map, $package);
        $this->updateUserfiles($package);
        $this->updateRootFiles($package);

        $root        = $this->plugin->getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
        $installPath = self::unprefixPath($root, $this->getInstallPath($package));
        RunonceManager::addRunonces($package, $installPath);
    }

    /**
     * {@inheritdoc}
     */
    public function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $map = $this->mapSources($initial);
        parent::updateCode($initial, $target);
        $this->updateSources($map, $target);
        $this->updateUserfiles($target);
        $this->updateRootFiles($target);

        $root        = $this->plugin->getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
        $installPath = self::unprefixPath($root, $this->getInstallPath($target));
        RunonceManager::addRunonces($target, $installPath);
    }

    /**
     * Update all files in the Contao installation.
     *
     * The installed files are:
     * 1. sources.
     * 2. user files.
     * 3. root files.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return void
     */
    public function updateContaoFiles(PackageInterface $package)
    {
        $map = $this->mapSources($package);
        $this->updateSources($map, $package);
        $this->updateUserfiles($package);
        $this->updateRootFiles($package);

        $root        = $this->plugin->getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
        $installPath = self::unprefixPath($root, $this->getInstallPath($package));
        RunonceManager::addRunonces($package, $installPath);
    }

    /**
     * {@inheritdoc}
     */
    public function removeCode(PackageInterface $package)
    {
        $this->removeSources($package);
        parent::removeCode($package);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSourcesSpec(PackageInterface $package)
    {
        $sources = array();

        if ($package->getType() == self::LEGACY_MODULE_TYPE) {
            $installPath = $this->getInstallPath($package);

            $this->createLegacySourcesSpec(
                $installPath,
                $installPath . '/TL_ROOT',
                $installPath . '/TL_ROOT/',
                $sources,
                $package
            );

            $userfiles = array();
            $this->createLegacySourcesSpec(
                $installPath,
                $installPath . '/TL_FILES',
                $installPath . '/TL_FILES/',
                $userfiles,
                $package
            );

            $extra                        = $package->getExtra();
            $extra['contao']['userfiles'] = $userfiles;
            $package->setExtra($extra);
        } else {
            $extra = $package->getExtra();

            if (array_key_exists('contao', $extra)) {
                if (array_key_exists('shadow-copies', $extra['contao'])) {
                    $sources = array_merge(
                        $sources,
                        $extra['contao']['shadow-copies']
                    );
                }
                if (array_key_exists('symlinks', $extra['contao'])) {
                    $sources = array_merge(
                        $sources,
                        $extra['contao']['symlinks']
                    );
                }
                if (array_key_exists('sources', $extra['contao'])) {
                    $sources = array_merge(
                        $sources,
                        $extra['contao']['sources']
                    );
                }
            }
        }

        return $sources;
    }

    /**
     * Create the spec list for legacy packages.
     *
     * @param string           $installPath The installation path.
     *
     * @param string           $startPath   The destination path.
     *
     * @param string           $currentPath The current working directory.
     *
     * @param array            $sources     The sources list.
     *
     * @param PackageInterface $package     The package being examined.
     *
     * @return void
     */
    protected function createLegacySourcesSpec(
        $installPath,
        $startPath,
        $currentPath,
        &$sources,
        PackageInterface $package
    ) {
        $sourcePath = self::unprefixPath($installPath . DIRECTORY_SEPARATOR, $currentPath);
        $targetPath = self::unprefixPath($startPath . DIRECTORY_SEPARATOR, $currentPath);

        if (self::getNativePath($targetPath, '/') == 'system/runonce.php') {
            $path = self::unprefixPath(
                $this->plugin->getContaoRoot($this->composer->getPackage()),
                $currentPath
            );
            RunonceManager::addRunonce($path);
        } elseif (is_file($currentPath)
            || preg_match(
                '#^system/modules/[^/]+$#',
                self::getNativePath($targetPath, '/')
            )
        ) {
            $sources[$sourcePath] = $targetPath;
        } elseif (is_dir($currentPath)) {
            $files = new \FilesystemIterator(
                $currentPath,
                (\FilesystemIterator::SKIP_DOTS |
                \FilesystemIterator::UNIX_PATHS |
                \FilesystemIterator::CURRENT_AS_PATHNAME)
            );

            foreach ($files as $file) {
                $this->createLegacySourcesSpec($installPath, $startPath, $file, $sources, $package);
            }
        }
    }

    /**
     * Map the sources to copy.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return array
     */
    protected function mapSources(PackageInterface $package)
    {
        $root    = $this->plugin->getContaoRoot($this->composer->getPackage());
        $sources = $this->getSourcesSpec($package);
        $map     = array(
            'copies' => array(),
            'links'  => array(),
        );

        foreach ($sources as $source => $target) {
            if (is_link($root . DIRECTORY_SEPARATOR . $target)) {
                $map['links'][$target] = readlink($root . DIRECTORY_SEPARATOR . $target);
            } elseif (is_dir($root . DIRECTORY_SEPARATOR . $target)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $root . DIRECTORY_SEPARATOR . $target,
                        (\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
                    )
                );

                /** @var \SplFileInfo $targetFile */
                foreach ($iterator as $targetFile) {
                    $pathname = self::unprefixPath($root . DIRECTORY_SEPARATOR, $targetFile->getRealPath());
                    $key      = ($source ? $source . DIRECTORY_SEPARATOR : '') .
                        self::unprefixPath(
                            $target . DIRECTORY_SEPARATOR,
                            $pathname
                        );

                    $map['copies'][$key] = $pathname;
                }
            } elseif (is_file($root . DIRECTORY_SEPARATOR . $target)) {
                $map['copies'][$source] = $target;
            }
        }

        return $map;
    }

    /**
     * Update the sources in the destination folder.
     *
     * @param array            $map     The sources to update.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return void
     */
    abstract protected function updateSources($map, PackageInterface $package);

    /**
     * Remove all obsolete sources.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return void
     */
    protected function removeSources(PackageInterface $package)
    {
        $map  = $this->mapSources($package);
        $root = $this->plugin->getContaoRoot($this->composer->getPackage());

        $count = 0;

        // remove symlinks
        foreach ($map['links'] as $link => $target) {
            $this->writeVerbose(
                sprintf(
                    '  - rm symlink <info>%s</info>',
                    $link
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $link);
            $count++;
        }

        // remove copies
        foreach ($map['copies'] as $target) {
            $this->writeVerbose(
                sprintf(
                    '  - rm file <info>%s</info>',
                    $target
                )
            );

            $this->filesystem->remove($root . DIRECTORY_SEPARATOR . $target);
            $count++;
            $this->removeEmptyDirectories(dirname($root . DIRECTORY_SEPARATOR . $target));
        }

        $this->write(
            sprintf(
                '  - removed <info>%d</info> files',
                $count
            )
        );
    }

    /**
     * Clean up empty directories.
     *
     * @param string $pathname The path in which empty directories should be removed.
     *
     * @return void
     */
    public function removeEmptyDirectories($pathname)
    {
        if (is_dir($pathname)) {
            $root = $this->plugin->getContaoRoot($this->composer->getPackage());

            $contents = array_filter(
                scandir($pathname),
                function ($item) {
                    return $item != '.' && $item != '..';
                }
            );
            if (empty($contents)) {
                $this->writeVerbose(
                    sprintf(
                        '  - rm dir <info>%s</info>',
                        self::unprefixPath($root, $pathname)
                    )
                );

                rmdir($pathname);
                $this->removeEmptyDirectories(dirname($pathname));
            }
        }
    }

    /**
     * Update the user files.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return void
     */
    public function updateUserfiles(PackageInterface $package)
    {
        $count = 0;

        $extra = $package->getExtra();
        if (array_key_exists('contao', $extra)) {
            $contao = $extra['contao'];

            if (is_array($contao) && array_key_exists('userfiles', $contao)) {
                $root       = $this->plugin->getContaoRoot($this->composer->getPackage());
                $uploadPath = $this->getUploadPath();

                $userfiles   = (array) $contao['userfiles'];
                $installPath = $this->getInstallPath($package);

                foreach ($userfiles as $source => $target) {
                    $target = $uploadPath . DIRECTORY_SEPARATOR . $target;

                    $sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
                    $targetReal = $root . DIRECTORY_SEPARATOR . $target;

                    $count += $this->installFiles($sourceReal, $targetReal, $target);
                }
            }
        }

        if ($count) {
            $this->writeVerbose(
                sprintf(
                    '  - installed <info>%d</info> userfiles',
                    $count
                )
            );
        }
    }

    /**
     * Update the root files.
     *
     * @param PackageInterface $package The package being processed.
     *
     * @return void
     */
    public function updateRootFiles(PackageInterface $package)
    {
        $count = 0;

        $extra = $package->getExtra();
        if (array_key_exists('contao', $extra)) {
            $contao = $extra['contao'];

            if (is_array($contao) && array_key_exists('files', $contao)) {
                $root        = $this->plugin->getContaoRoot($this->composer->getPackage());
                $files       = (array) $contao['files'];
                $installPath = $this->getInstallPath($package);

                foreach ($files as $source => $target) {
                    $target = DIRECTORY_SEPARATOR . $target;

                    $sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
                    $targetReal = $root . DIRECTORY_SEPARATOR . $target;

                    $count += $this->installFiles($sourceReal, $targetReal, $target);
                }
            }
        }

        if ($count) {
            $this->writeVerbose(
                sprintf(
                    '  - installed <info>%d</info> files',
                    $count
                )
            );
        }
    }

    /**
     *  Install files into the target folder.
     *
     * @param string $sourceReal The source filename/directory.
     *
     * @param string $targetReal The destination filename/directory.
     *
     * @param string $target     The target filename.
     *
     * @return int
     */
    protected function installFiles($sourceReal, $targetReal, $target)
    {
        if (!is_dir($sourceReal)) {
            if (file_exists($targetReal)) {
                $this->write(
                    sprintf(
                        '  - not overwriting already present userfile <info>%s</info>',
                        $targetReal
                    )
                );

                return 0;
            }

            $targetPath = dirname($targetReal);
            $this->filesystem->ensureDirectoryExists($targetPath);
            $this->writeVerbose(
                sprintf(
                    '  - install userfile <info>%s</info>',
                    $target
                )
            );
            copy($sourceReal, $targetReal);
            return 1;
        }

        $count    = 0;
        $iterator = new RecursiveDirectoryIterator(
            $sourceReal,
            (RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
        );
        $iterator = new RecursiveIteratorIterator(
            $iterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        if (!file_exists($targetReal)) {
            $this->filesystem->ensureDirectoryExists($targetReal);
        }

        /** @var RecursiveDirectoryIterator $iterator */
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file*/
            $targetPath = $targetReal . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if (!file_exists($targetPath)) {
                if ($file->isDir()) {
                    $this->filesystem->ensureDirectoryExists($targetPath);
                } else {
                    $this->writeVerbose(
                        sprintf(
                            '  - install userfile <info>%s</info>',
                            $iterator->getSubPathName()
                        )
                    );
                    copy($file->getPathname(), $targetPath);
                    $count++;
                }
            } else {
                $this->write(
                    sprintf(
                        '  - not overwriting already present userfile <info>%s</info>',
                        $iterator->getSubPathName()
                    )
                );
            }
        }

        return $count;
    }

    /**
     * Get the Contao upload path.
     *
     * @return string
     */
    protected function getUploadPath()
    {
        return $this->plugin->getContaoUploadPath();
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return self::MODULE_TYPE === $packageType || self::LEGACY_MODULE_TYPE == $packageType;
    }
}
