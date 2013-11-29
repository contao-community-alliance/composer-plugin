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

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Autoload\ClassMapGenerator;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Installer\LibraryInstaller;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;

/**
 * Installer that install Contao extensions via shadow copies or symlinks
 * into the Contao file hierarchy.
 */
class ModuleInstaller extends LibraryInstaller
{
	/**
	 * Module type of contao packages.
	 */
	const MODULE_TYPE = 'contao-module';

	/**
	 * Module type of converted ER2 contao packages.
	 */
	const LEGACY_MODULE_TYPE = 'legacy-contao-module';

	static public function getPreferredInstall(Composer $composer)
	{
		return $composer
			->getConfig()
			->get('preferred-install');
	}

	static public function isDistInstallPreferred(Composer $composer)
	{
		return static::getPreferredInstall($composer) == 'dist';
	}

	/**
	 * @deprecated
	 *
	 * @param Event $event
	 */
	static public function updateContaoPackage(Event $event)
	{
		static::preUpdate($event);
	}

	/**
	 * @deprecated
	 *
	 * @param Event $event
	 */
	static public function updateComposerConfig(Event $event)
	{
		static::preUpdate($event);
	}

	static public function preUpdate(Event $event)
	{
		$io       = $event->getIO();
		$composer = $event->getComposer();

		ConfigManipulator::run($io, $composer);
	}

	static public function createRunonce(Event $event)
	{
		static::postUpdate($event);
	}

	static public function postUpdate(Event $event)
	{
		$io   = $event->getIO();
		$root = Plugin::getContaoRoot(
			$event
				->getComposer()
				->getPackage()
		);

		RunonceManager::createRunonce($io, $root);
		static::cleanCache($io, $root);
	}

	static public function cleanCache(IOInterface $io, $root)
	{
		// clean cache
		$fs = new Filesystem();
		foreach (array('config', 'dca', 'language', 'sql') as $dir) {
			$cache = $root . '/system/cache/' . $dir;
			if (is_dir($cache)) {
				$io->write(
					sprintf(
						'<info>Clean contao internal %s cache</info>',
						$dir
					)
				);
				$fs->removeDirectory($cache);
			}
		}
	}

	static public function postAutoloadDump(Event $event)
	{
		$root = Plugin::getContaoRoot(
			$event
				->getComposer()
				->getPackage()
		);

		$localconfig = $root . '/system/config/localconfig.php';
		if (file_exists($localconfig)) {
			$lines    = file($localconfig);
			$remove   = false;
			$modified = false;
			foreach ($lines as $index => $line) {
				$tline = trim($line);
				if ($tline == '### COMPOSER CLASSES START ###') {
					$modified = true;
					$remove   = true;
					unset($lines[$index]);
				}
				else if ($tline == '### COMPOSER CLASSES STOP ###') {
					$remove   = false;
					unset($lines[$index]);
				}
				else if ($remove || $tline == '?>') {
					unset($lines[$index]);
				}
			}

			if ($modified) {
				$file = implode('', $lines);
				$file = rtrim($file);

				file_put_contents($root . '/system/config/localconfig.php', $file);
			}
		}
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

		$root        = Plugin::getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
		$installPath = self::unprefixPath($root, $this->getInstallPath($package));
		RunonceManager::addRunonces($package, $installPath);
	}

	public function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		$map = $this->mapSources($initial);
		parent::updateCode($initial, $target);
		$this->updateSources($map, $target, $initial);
		$this->updateUserfiles($target);

		$root        = Plugin::getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
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
				Plugin::getContaoRoot($package),
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
		$root    = Plugin::getContaoRoot($this->composer->getPackage());
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

					$key = ($source ? $source . DIRECTORY_SEPARATOR : '') . self::unprefixPath(
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

	protected function updateSources($map, PackageInterface $package)
	{
		$root        = Plugin::getContaoRoot($this->composer->getPackage());
		$installPath = $this->getInstallPath($package);
		$sources     = $this->getSourcesSpec($package);

		$deleteCount = 0;
		$linkCount   = 0;
		$copyCount   = 0;

		// use copies
		if (static::isDistInstallPreferred($this->composer)) {
			// remove symlinks
			foreach ($map['links'] as $link => $target) {
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - rm link <info>%s</info>',
							$link
						)
					);
				}

				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $link);
				$deleteCount++;
			}

			// update copies
			$copies = array();
			foreach ($sources as $source => $target) {
				if (is_dir($installPath . DIRECTORY_SEPARATOR . $source)) {
					$files = array();
					$iterator = new \RecursiveDirectoryIterator(
						$installPath . DIRECTORY_SEPARATOR . $source,
						\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
					);
					$iterator = new \RecursiveIteratorIterator(
						$iterator
					);
					foreach ($iterator as $sourceFile) {
						$unPrefixedPath     = self::unprefixPath(
							$installPath . DIRECTORY_SEPARATOR . ($source ? $source . DIRECTORY_SEPARATOR : ''),
							$sourceFile->getRealPath()
						);
						$targetPath         = $target . DIRECTORY_SEPARATOR . $unPrefixedPath;
						$files[$targetPath] = $sourceFile;
					}
				}
				else {
					$files = array($target => new \SplFileInfo($installPath . DIRECTORY_SEPARATOR . $source));
				}

				/** @var \SplFileInfo $sourceFile */
				foreach ($files as $targetPath => $sourceFile) {
					if ($this->io->isVerbose()) {
						$this->io->write(
							sprintf(
								'  - cp <info>%s</info>',
								$targetPath
							)
						);
					}

					$this->filesystem->ensureDirectoryExists(dirname($root . DIRECTORY_SEPARATOR . $targetPath));
					copy($sourceFile->getRealPath(), $root . DIRECTORY_SEPARATOR . $targetPath);
					$copyCount++;
					$copies[] = $targetPath;
				}
			}

			$obsoleteCopies = array_diff($map['copies'], $copies);
			foreach ($obsoleteCopies as $obsoleteCopy) {
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - rm obsolete <info>%s</info>',
							$obsoleteCopy
						)
					);
				}
				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsoleteCopy);
				$deleteCount++;
			}
		}

		// use symlinks
		else {
			// remove copies
			foreach ($map['copies'] as $target) {
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - rm copy <info>%s</info>',
							$target
						)
					);
				}

				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $target);
				$deleteCount++;
				$this->removeEmptyDirectories(dirname($root . DIRECTORY_SEPARATOR . $target));
			}

			// update symlinks
			$links = array();
			foreach ($sources as $target => $link) {
				$targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
				$linkReal   = $root . DIRECTORY_SEPARATOR . $link;
				$linkRel    = self::unprefixPath($root . DIRECTORY_SEPARATOR, $linkReal);

				if (file_exists($linkReal)) {
					if (!is_link($linkReal)) {
						throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
					}
				}

				$targetParts = array_values(
					array_filter(
						explode(DIRECTORY_SEPARATOR, $targetReal)
					)
				);
				$linkParts   = array_values(
					array_filter(
						explode(DIRECTORY_SEPARATOR, $linkReal)
					)
				);

				// calculate a relative link target
				$linkTargetParts = array();

				while (count($targetParts) && count($linkParts) && $targetParts[0] == $linkParts[0]) {
					array_shift($targetParts);
					array_shift($linkParts);
				}

				$n = count($linkParts);
				// start on $i=1 -> skip the link name itself
				for ($i = 1; $i < $n; $i++) {
					$linkTargetParts[] = '..';
				}

				$linkTargetParts = array_merge(
					$linkTargetParts,
					$targetParts
				);

				$linkTarget = implode(DIRECTORY_SEPARATOR, $linkTargetParts);

				$links[] = $linkRel;

				if (is_link($linkReal)) {
					// link target has changed
					if (readlink($linkReal) != $linkTarget) {
						unlink($linkReal);
					}
					// link exists and have the correct target
					else {
						continue;
					}
				}

				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - symlink <info>%s</info>',
							$linkRel
						)
					);
				}

				$linkParent = dirname($linkReal);
				if (!is_dir($linkParent)) {
					mkdir($linkParent, 0777, true);
				}

				symlink($linkTarget, $linkReal);
				$linkCount++;
			}

			// remove obsolete links
			$obsoleteLinks = array_diff(array_keys($map['links']), $links);
			foreach ($obsoleteLinks as $obsoleteLink) {
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							'  - rm symlink <info>%s</info>',
							$obsoleteLink
						)
					);
				}

				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsoleteLink);
				$deleteCount++;
			}
		}

		if ($deleteCount && !$this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - removed <info>%d</info> files',
					$deleteCount
				)
			);
		}

		if ($linkCount && !$this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - created <info>%d</info> links',
					$linkCount
				)
			);
		}

		if ($copyCount && !$this->io->isVerbose()) {
			$this->io->write(
				sprintf(
					'  - installed <info>%d</info> files',
					$copyCount
				)
			);
		}
	}

	protected function removeSources(PackageInterface $package)
	{
		$map  = $this->mapSources($package);
		$root = Plugin::getContaoRoot($this->composer->getPackage());

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
			$root = Plugin::getContaoRoot($this->composer->getPackage());

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
				$root       = Plugin::getContaoRoot($this->composer->getPackage());
				$uploadPath = $GLOBALS['TL_CONFIG']['uploadPath'];

				$userfiles   = (array) $contao['userfiles'];
				$installPath = $this->getInstallPath($package);

				foreach ($userfiles as $source => $target) {
					$target = $uploadPath . DIRECTORY_SEPARATOR . $target;

					$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
					$targetReal = $root . DIRECTORY_SEPARATOR . $target;

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

						foreach ($iterator as $file) {
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
							continue;
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
	 * {@inheritDoc}
	 */
	public function supports($packageType)
	{
		return self::MODULE_TYPE === $packageType || self::LEGACY_MODULE_TYPE == $packageType;
	}
}
