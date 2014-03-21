<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Dominik Zogg <dominik.zogg@gmail.com>
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
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
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * @param IOInterface $inputOutput
	 *
	 * @param Composer    $composer
	 *
	 * @param Plugin      $plugin
	 */
	public function __construct(IOInterface $inputOutput, Composer $composer, $plugin)
	{
		parent::__construct($inputOutput, $composer);
		$this->plugin = $plugin;
	}

	static public function unprefixPath($prefix, $path)
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

	static public function getNativePath($path, $sep = DIRECTORY_SEPARATOR)
	{
		return str_replace(array('/', '\\'), $sep, $path);
	}

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

	public function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		$map = $this->mapSources($initial);
		parent::updateCode($initial, $target);
		$this->updateSources($map, $target, $initial);
		$this->updateUserfiles($target);
		$this->updateRootFiles($target);

		$root        = $this->plugin->getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
		$installPath = self::unprefixPath($root, $this->getInstallPath($target));
		RunonceManager::addRunonces($target, $installPath);
	}

	public function removeCode(PackageInterface $package)
	{
		$this->removeSources($package);
		parent::removeCode($package);
	}

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
		}
		else {
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
		}
		else if (is_file($currentPath) || preg_match(
				'#^system/modules/[^/]+$#',
				self::getNativePath($targetPath, '/')
			)
		) {
			$sources[$sourcePath] = $targetPath;
		}
		else if (is_dir($currentPath)) {
			$files = new \FilesystemIterator(
				$currentPath,
				\FilesystemIterator::SKIP_DOTS |
				\FilesystemIterator::UNIX_PATHS |
				\FilesystemIterator::CURRENT_AS_PATHNAME
			);

			foreach ($files as $file) {
				$this->createLegacySourcesSpec($installPath, $startPath, $file, $sources, $package);
			}
		}
	}

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
			}
			else if (is_dir($root . DIRECTORY_SEPARATOR . $target)) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator(
						$root . DIRECTORY_SEPARATOR . $target,
						\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
					)
				);

				/** @var \SplFileInfo $targetFile */
				foreach ($iterator as $targetFile) {
					$pathname = self::unprefixPath($root . DIRECTORY_SEPARATOR, $targetFile->getRealPath());

					$key                 = ($source ? $source . DIRECTORY_SEPARATOR : '') . self::unprefixPath(
							$target . DIRECTORY_SEPARATOR,
							$pathname
						);
					$map['copies'][$key] = $pathname;
				}
			}
			else if (is_file($root . DIRECTORY_SEPARATOR . $target)) {
				$map['copies'][$source] = $target;
			}
		}

		return $map;
	}

	abstract protected function updateSources($map, PackageInterface $package);

	protected function removeSources(PackageInterface $package)
	{
		$map  = $this->mapSources($package);
		$root = $this->plugin->getContaoRoot($this->composer->getPackage());

		$count = 0;

		// remove symlinks
		foreach ($map['links'] as $link => $target) {
			if ($this->io->isVerbose()) {
				$this->io->write(
					sprintf(
						'  - rm symlink <info>%s</info>',
						$link
					)
				);
			}

			$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $link);
			$count++;
		}

		// remove copies
		foreach ($map['copies'] as $target) {
			if ($this->io->isVerbose()) {
				$this->io->write(
					sprintf(
						'  - rm file <info>%s</info>',
						$target
					)
				);
			}

			$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $target);
			$count++;
			$this->removeEmptyDirectories(dirname($root . DIRECTORY_SEPARATOR . $target));
		}

		if (!$this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - removed <info>%d</info> files',
					$count
				)
			);
		}
	}

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
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - rm dir <info>%s</info>',
							self::unprefixPath($root, $pathname)
						)
					);
				}

				rmdir($pathname);
				$this->removeEmptyDirectories(dirname($pathname));
			}
		}
	}

	/**
	 * @param PackageInterface $package
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

		if ($count && $this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - installed <info>%d</info> userfiles',
					$count
				)
			);
		}
	}

	/**
	 * @param PackageInterface $package
	 */
	public function updateRootFiles(PackageInterface $package)
	{
		$count = 0;

		$extra = $package->getExtra();
		if (array_key_exists('contao', $extra)) {
			$contao = $extra['contao'];

			if (is_array($contao) && array_key_exists('files', $contao)) {
				$root       = $this->plugin->getContaoRoot($this->composer->getPackage());

				$files   = (array) $contao['files'];
				$installPath = $this->getInstallPath($package);

				foreach ($files as $source => $target) {
					$target = DIRECTORY_SEPARATOR . $target;

					$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
					$targetReal = $root . DIRECTORY_SEPARATOR . $target;

					$count += $this->installFiles($sourceReal, $targetReal, $target);
				}
			}
		}

		if ($count && $this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - installed <info>%d</info> files',
					$count
				)
			);
		}
	}

	protected function installFiles($sourceReal, $targetReal, $target)
	{
		$count = 0;

		if (is_dir($sourceReal)) {
			$iterator = new RecursiveDirectoryIterator(
				$sourceReal,
				RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
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
					}
					else {
						if ($this->io->isVerbose()) {
							$this->io->write(
								sprintf(
									'  - install userfile <info>%s</info>',
									$iterator->getSubPathName()
								)
							);
						}
						copy($file->getPathname(), $targetPath);
						$count++;
					}
				}
			}
		}
		else {
			if (file_exists($targetReal)) {
				return $count;
			}

			$targetPath = dirname($targetReal);
			$this->filesystem->ensureDirectoryExists($targetPath);
			if ($this->io->isVerbose()) {
				$this->io->write(
					sprintf(
						'  - install userfile <info>%s</info>',
						$target
					)
				);
			}
			copy($sourceReal, $targetReal);
			$count++;
		}

		return $count;
	}

	/**
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
