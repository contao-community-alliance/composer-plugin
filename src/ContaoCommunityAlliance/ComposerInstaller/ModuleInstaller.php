<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Installer\LibraryInstaller;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;

class ModuleInstaller extends LibraryInstaller
{
	const MODULE_TYPE = 'contao-module';

	const LEGACY_MODULE_TYPE = 'legacy-contao-module';

	static protected $runonces = array();

	static public function getContaoRoot(PackageInterface $package)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());

			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra) && array_key_exists('root', $extra['contao'])) {
				$root = getcwd() . '/' . $extra['contao']['root'];
			}
			// test, do we have the core within vendor/contao/core.
			else if (is_dir(getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core')) {
				$root = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core';
			}

			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		if (!defined('VERSION')) {
			// Contao 3+
			if (file_exists(
				$constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			// Contao 2+
			else if (file_exists(
				$constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			else {
				throw new \Exception('Could not find constants.php in ' . $root);
			}
		}

		if (empty($GLOBALS['TL_CONFIG'])) {
			if (version_compare(VERSION, '3', '>=')) {
				// load default.php
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.php');
			}
			else {
				// load config.php
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
			}

			// load localconfig.php
			if (file_exists(
				$root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'localconfig.php'
			)
			) {
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'localconfig.php');
			}
		}

		return $root;
	}

	static public function updateContaoPackage(Event $event)
	{
		$composer = $event->getComposer();

		/** @var \Composer\Package\RootPackage $package */
		$package = $composer->getPackage();

		// load constants
		static::getContaoRoot($package);

		$versionParser = new VersionParser();

		$version       = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);

		if ($package->getVersion() !== $prettyVersion) {
			$configFile            = new JsonFile('composer.json');
			$configJson            = $configFile->read();
			$configJson['version'] = $version;
			$configFile->write($configJson);

			$io = $event->getIO();
			$io->write(
				"Contao version changed from <info>" . $package->getPrettyVersion(
				) . "</info> to <info>" . $version . "</info>, please restart composer"
			);
			exit;
		}
	}

	static public function createRunonce(Event $event)
	{
		$runonces = & static::$runonces;
		if (count($runonces)) {
			$root = static::getContaoRoot($event->getComposer()->getPackage());
			$file = 'system/runonce.php';
			$n    = 0;
			while (file_exists($root . DIRECTORY_SEPARATOR . $file)) {
				$n++;
				$file = 'system/runonce_' . $n . '.php';
			}
			if ($n > 0) {
				rename(
					$root . '/system/runonce.php',
					$root . DIRECTORY_SEPARATOR . $file
				);
				array_unshift(
					$runonces,
					$file
				);
			}

			$template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'RunonceExecutorTemplate.php');
			$template = str_replace(
				'TEMPLATE_RUNONCE_ARRAY',
				var_export($runonces, true),
				$template
			);
			file_put_contents($root . '/system/runonce.php', $template);

			$io = $event->getIO();
			$io->write("<info>Runonce created with " . count($runonces) . " updates</info>");
			foreach ($runonces as $runonce) {
				$io->write("  - " . $runonce);
			}
		}
	}

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateShadowCopies($package);
		$this->updateSymlinks($package);
		$this->updateUserfiles($package);
		$this->updateRunonce($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		$this->playBackShadowCopies($initial);
		parent::updateCode($initial, $target);
		$this->updateShadowCopies($target, $initial);
		$this->updateSymlinks($target, $initial);
		$this->updateUserfiles($target);
		$this->updateRunonce($target);
	}

	protected function removeCode(PackageInterface $package)
	{
		$this->removeShadowCopies($package);
		parent::removeCode($package);
		$this->removeSymlinks($package);
	}

	protected function playBackShadowCopies(PackageInterface $package)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($root) {
				// copy back existing files
				if (file_exists($targetFile->getPathname()) &&
					md5_file($sourceFile->getPathname()) != md5_file($targetFile->getPathname())
				) {
					$this->io->write(
						sprintf(
							"  - play back modified shadow copy <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $targetFile->getPathname()),
							str_replace($root, '', $sourceFile->getPathname())
						)
					);
					copy($targetFile->getPathname(), $sourceFile->getPathname());
				}
			}
		);
	}

	protected function updateShadowCopies(PackageInterface $package, PackageInterface $initial = null)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($root) {
				// copy non existing files
				if (!file_exists($targetFile->getPathname())) {
					$dir = dirname($targetFile->getPathname());
					if (!is_dir($dir)) {
						mkdir($dir, 0777, true);
					}
					$this->io->write(
						sprintf(
							"  - create shadow copy <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $sourceFile->getPathname()),
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					copy($sourceFile->getPathname(), $targetFile->getPathname());
				}

				// copy if file changed
				else if (md5_file($sourceFile->getPathname()) != md5_file($targetFile->getPathname())) {
					$this->io->write(
						sprintf(
							"  - update shadow copy <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $sourceFile->getPathname()),
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					copy($sourceFile->getPathname(), $targetFile->getPathname());
				}
			}
		);
	}

	protected function removeShadowCopies(PackageInterface $package)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($root) {
				// remove existing shadow copies
				if (file_exists($targetFile->getPathname())) {
					$this->io->write(
						sprintf(
							"  - remove shadow copy <info>%s</info>",
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					unlink($targetFile->getPathname());
				}
			}
		);
	}

	protected function walkShadowCopies(PackageInterface $package, $closure)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		if ($package->getType() == self::LEGACY_MODULE_TYPE) {
			$downloadPath = $this->getInstallPath($package);

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					$downloadPath,
					\FilesystemIterator::SKIP_DOTS
				)
			);

			/** @var \SplFileInfo $sourceFile */
			foreach ($iterator as $sourceFile) {
				$pathname = str_replace($downloadPath . '/', '', $sourceFile->getRealPath());

				if (preg_match('#^(TL_ROOT|TL_FILES)/(.*)$#e', $pathname, $matches)) {
					if ($matches[2] == 'system/runonce.php') {
						static::$runonces[] = $pathname;
						continue;
					}

					switch ($matches[1]) {
						case 'TL_ROOT':
							$base     = $root;
							$userfile = false;
							break;
						case 'TL_FILES':
							$base     = $GLOBALS['TL_CONFIG']['uploadPath'];
							$userfile = true;
							break;
						default:
							continue;
					}

					$target     = $base . '/' . $matches[2];
					$targetFile = new \SplFileInfo($target);

					$closure($sourceFile, $targetFile, $userfile);
				}
			}
		}

		else {
			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (array_key_exists('shadow-copies', $contao)) {
					$shadowCopies = (array) $contao['shadow-copies'];

					foreach ($shadowCopies as $source => $target) {
						$sourceFile = new \SplFileInfo($root . '/' . $source);
						$targetFile = new \SplFileInfo($root . '/' . $target);

						$closure($sourceFile, $targetFile, false);
					}
				}
			}
		}
	}

	protected function removeEmptyDirectories($pathname)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$contents = array_filter(
			scandir($pathname),
			function ($item) {
				return $item != '.' && $item != '..';
			}
		);
		if (empty($contents)) {
			$this->io->write(
				sprintf(
					"  - remove empty directory <info>%s</info>",
					str_replace($root, '', $pathname)
				)
			);
			rmdir($pathname);
			$this->removeEmptyDirectories(dirname($pathname));
		}
	}

	protected function calculateSymlinkMap(PackageInterface $package)
	{
		$map   = array();
		$extra = $package->getExtra();
		if (array_key_exists('contao', $extra)) {
			$contao = $extra['contao'];

			if (!is_array($contao)) {
				return;
			}
			if (!array_key_exists('symlinks', $contao)) {
				$contao['symlinks'] = array();
			}

			// symlinks disabled
			if ($contao['symlinks'] === false) {
				return array();
			}

			$symlinks = (array) $contao['symlinks'];

			// add fallback symlink
			if (empty($symlinks)) {
				$symlinks[''] = 'system/modules/' . preg_replace('#^.*/#', '', $package->getName());
			}

			$root = static::getContaoRoot($this->composer->getPackage());
			$installPath = $this->getInstallPath($package);

			foreach ($symlinks as $target => $link) {
				$targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
				$linkReal   = $root . DIRECTORY_SEPARATOR . $link;

				if (file_exists($linkReal)) {
					if (!is_link($linkReal)) {
						// special behavior for composer extension
						if ($package->getName() == 'contao-community-alliance/composer') {
							$this->filesystem->removeDirectory($root . '/system/modules/!composer');
						}
						else {
							throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
						}
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

				$map[$linkReal] = $linkTarget;
			}
		}
		return $map;
	}

	protected function updateSymlinks(PackageInterface $package, PackageInterface $initial = null)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$map = $this->calculateSymlinkMap($package);

			$root = static::getContaoRoot($this->composer->getPackage());

			if ($initial) {
				$previousMap = $this->calculateSymlinkMap($initial);

				$obsoleteLinks = array_diff(
					array_keys($previousMap),
					array_keys($map)
				);

				foreach ($obsoleteLinks as $linkReal) {
					if (is_link($linkReal)) {
						$this->io->write(
							"  - Remove symlink <info>" . str_replace(
								$root,
								'',
								$linkReal
							) . "</info> to <info>" . readlink(
								$linkReal
							) . "</info> for package <info>" . $package->getName(
							) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)"
						);
						unlink($linkReal);
					}
				}
			}

			foreach ($map as $linkReal => $linkTarget) {
				if (!is_link($linkReal) || readlink($linkReal) != $linkTarget) {
					if (is_link($linkReal)) {
						unlink($linkReal);
					}
					$this->io->write(
						"  - Create symlink <info>" . str_replace(
							$root,
							'',
							$linkReal
						) . "</info> to <info>" . $linkTarget . "</info> for package <info>" . $package->getName(
						) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)"
					);
					$dir = dirname($linkReal);
					if (!is_dir($dir)) {
						mkdir($dir, 0777, true);
					}
					symlink($linkTarget, $linkReal);
				}
			}
		}
	}

	protected function removeSymlinks(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$map = $this->calculateSymlinkMap($package);

			$root = static::getContaoRoot($this->composer->getPackage());

			foreach ($map as $linkReal => $linkTarget) {
				if (is_link($linkReal)) {
					$this->io->write(
						"  - Remove symlink <info>" . str_replace(
							$root,
							'',
							$linkReal
						) . "</info> to <info>" . readlink(
							$linkReal
						) . "</info> for package <info>" . $package->getName(
						) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)"
					);
					unlink($linkReal);
				}
			}
		}
	}

	protected function updateUserfiles(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (is_array($contao) && array_key_exists('userfiles', $contao)) {
					$root = static::getContaoRoot($this->composer->getPackage());
					$uploadPath = $GLOBALS['TL_CONFIG']['uploadPath'];

					$userfiles   = (array) $contao['userfiles'];
					$installPath = $this->getInstallPath($package);

					foreach ($userfiles as $source => $target) {
						$target = $uploadPath . DIRECTORY_SEPARATOR . $target;

						$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
						$targetReal = $root . DIRECTORY_SEPARATOR . $target;

						$it = new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS);
						$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

						if (!file_exists($targetReal)) {
							mkdir($targetReal, 0777, true);
						}

						foreach ($ri as $file) {
							$targetPath = $targetReal . DIRECTORY_SEPARATOR . $ri->getSubPathName();
							if (!file_exists($targetPath)) {
								if ($file->isDir()) {
									mkdir($targetPath);
								}
								else {
									$this->io->write(
										"  - Copy userfile <info>" . $ri->getSubPathName(
										) . "</info> to <info>" . $target . DIRECTORY_SEPARATOR . $ri->getSubPathName(
										) . "</info> from package <info>" . $package->getName(
										) . "</info> (<comment>" . VersionParser::formatVersion(
											$package
										) . "</comment>)"
									);
									copy($file->getPathname(), $targetPath);
								}
							}
						}
					}
				}
			}
		}
	}

	protected function updateRunonce(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (is_array($contao) && array_key_exists('runonce', $contao)) {
					$root     = static::getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
					$runonces = (array) $contao['runonce'];

					$installPath = str_replace($root, '', $this->getInstallPath($package));

					foreach ($runonces as $file) {
						static::$runonces[] = $installPath . DIRECTORY_SEPARATOR . $file;
					}
				}
			}
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