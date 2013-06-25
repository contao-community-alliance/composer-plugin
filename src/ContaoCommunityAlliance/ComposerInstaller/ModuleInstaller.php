<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Autoload\ClassMapGenerator;
use Composer\Composer;
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

class ModuleInstaller extends LibraryInstaller
{
	const MODULE_TYPE = 'contao-module';

	const LEGACY_MODULE_TYPE = 'legacy-contao-module';

	static public $runonces = array();

	static public function getContaoRoot(PackageInterface $package)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());

			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra) && array_key_exists('root', $extra['contao'])) {
				$root = getcwd() . DIRECTORY_SEPARATOR . $extra['contao']['root'];
			}
			// test, do we have the core within vendor/contao/core.
			else if (is_dir(
				getcwd(
				) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core'
			)
			) {
				$root = getcwd(
					) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core';
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
		$composer = $event->getComposer();

		/** @var \Composer\Package\RootPackage $package */
		$package = $composer->getPackage();

		// load constants
		$root = static::getContaoRoot($package);


		$messages     = array();
		$jsonModified = false;
		$configFile   = new JsonFile('composer.json');
		$configJson   = $configFile->read();


		// remove old installer scripts
		foreach (
			array(
				'pre-update-cmd'  => array(
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateContaoPackage',
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateComposerConfig'
				),
				'post-update-cmd' => array(
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::createRunonce'
				),
			) as $key => $scripts
		) {
			foreach ($scripts as $script) {
				if (array_key_exists($key, $configJson['scripts'])) {
					if (is_array($configJson['scripts'][$key])) {
						$index = array_search($script, $configJson['scripts'][$key]);
						if ($index !== false) {
							unset($configJson['scripts'][$key][$index]);
							if (empty($configJson['scripts'][$key])) {
								unset($configJson['scripts'][$key]);
							}

							$jsonModified = true;
							$messages[]   = 'obsolete ' . $key . ' script was removed!';
						}
					}
					else if ($configJson['scripts'][$key] == $script) {
						unset($configJson['scripts'][$key]);

						$jsonModified = true;
						$messages[]   = 'obsolete ' . $key . ' script was removed!';
					}
				}
			}
		}


		// add installer scripts
		foreach (
			array(
				'pre-update-cmd'     => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
				'post-update-cmd'    => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate',
				'post-autoload-dump' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
			) as $key => $script
		) {
			if (!array_key_exists($key, $configJson['scripts']) || empty($configJson['scripts'][$key])) {
				$configJson['scripts'][$key] = $script;

				$jsonModified = true;
				$messages[]   = $key . ' script was missing and readded!';
			}
			else if (is_array($configJson['scripts'][$key])) {
				if (!in_array($script, $configJson['scripts'][$key])) {
					$configJson['scripts'][$key][] = $script;

					$jsonModified = true;
					$messages[]   = $key . ' script was missing and readded!';
				}
			}
			else if ($configJson['scripts'][$key] != $script) {
				$configJson['scripts'][$key] = $script;

				$jsonModified = true;
				$messages[]   = $key . ' script was missing and readded!';
			}
		}


		// add custom repository
		if (!array_key_exists('repositories', $configJson)) {
			$configJson['repositories'] = array();
		}

		$artifactPath = $root . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'packages';

		// remove outdated artifact repositories
		if (!isset($configJson['extra']['contao']['artifactPath'])) {
			$outdatedArtifactPath = 'packages';
		} elseif ($artifactPath != $configJson['extra']['contao']['artifactPath']) {
			$outdatedArtifactPath = $configJson['extra']['contao']['artifactPath'];
		}
		if (isset($outdatedArtifactPath)) {
			$configJson['repositories'] = array_filter(
				$configJson['repositories'],
				function ($repository) use ($outdatedArtifactPath) {
					return $repository['type'] != 'artifact' || $repository['url'] != $outdatedArtifactPath;
				}
			);
			$configJson['extra']['contao']['artifactPath'] = $artifactPath;
			$jsonModified = true;
			$messages[] = 'The artifact repository path was missing or outdated and has been set up to date! Please restart the last operation.';
		}

		// add current artifact repositories, if it is missing
		foreach ($configJson['repositories'] as $repository) {
			if ($repository['type'] == 'artifact' && $repository['url'] == $artifactPath) {
				$hasArtifactRepository = true;
				break;
			}
		}
		if (!isset($hasArtifactRepository)) {
			$configJson['repositories'][] = array(
				'type' => 'artifact',
				'url'  => $artifactPath
			);
			$jsonModified = true;
			$messages[] = 'The artifact repository was missing and has been added to repositories! Please restart the last operation.';
		}
		if (!is_dir($artifactPath)) {
			mkdir($artifactPath, 0777, true);
		}

		$hasContaoRepository = false;
		foreach ($configJson['repositories'] as $repository) {
			if ($repository['type'] == 'composer' &&
				($repository['url'] == 'http://legacy-packages-via.contao-community-alliance.org/' ||
					$repository['url'] == 'https://legacy-packages-via.contao-community-alliance.org/')
			) {
				$hasContaoRepository = true;
				break;
			}
		}

		// add contao repository
		if (!$hasContaoRepository) {
			$configJson['repositories'][] = array(
				'type' => 'composer',
				'url'  => 'https://legacy-packages-via.contao-community-alliance.org/'
			);

			$jsonModified = true;
			$messages[]   = 'The contao repository is missing and has been readded to repositories!';
		}


		// add contao-community-alliance/composer dependency
		if (!array_key_exists('contao-community-alliance/composer', $configJson['require'])) {
			$configJson['require']['contao-community-alliance/composer'] = '*';

			$jsonModified = true;
			$messages[]   = 'The contao integration contao-community-alliance/composer is missing and has been readded to dependencies!';
		}


		// update contao version
		$versionParser = new VersionParser();
		$version       = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);
		if ($package->getVersion() !== $prettyVersion) {
			$configJson['version'] = $version;

			$jsonModified = true;
			$messages[]   = sprintf(
				'Contao version changed from <info>%s</info> to <info>%s</info>!',
				$package->getPrettyVersion(),
				$version
			);
		}


		if ($jsonModified) {
			$configFile->write($configJson);
		}
		if (count($messages)) {
			$exception = null;
			foreach (array_reverse($messages) as $message) {
				$exception = new \RuntimeException($message, 0, $exception);
			}
			throw $exception;
		}
	}

	static public function createRunonce(Event $event)
	{
		static::postUpdate($event);
	}

	static public function postUpdate(Event $event)
	{
		$io   = $event->getIO();
		$root = static::getContaoRoot(
			$event
				->getComposer()
				->getPackage()
		);

		// create runonce
		$runonces = & static::$runonces;
		if (count($runonces)) {
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

			$io->write("<info>Runonce created with " . count($runonces) . " updates</info>");
			foreach ($runonces as $runonce) {
				$io->write("  - " . $runonce);
			}
		}

		// clean cache
		$fs = new Filesystem();
		foreach (array('config', 'dca', 'language', 'sql') as $dir) {
			$cache = $root . '/system/cache/' . $dir;
			if (is_dir($cache)) {
				$io->write("<info>Clean contao internal " . $dir . " cache</info>");
				$fs->removeDirectory($cache);
			}
		}
	}

	static public function postAutoloadDump(Event $event)
	{
		$root = static::getContaoRoot(
			$event
				->getComposer()
				->getPackage()
		);

		$localconfig = $root . '/system/config/localconfig.php';
		$lines       = file($localconfig);
		$remove      = false;
		foreach ($lines as $index => $line) {
			$tline = trim($line);
			if ($tline == '### COMPOSER CLASSES START ###') {
				$remove = true;
				unset($lines[$index]);
			}
			else if ($tline == '### COMPOSER CLASSES STOP ###') {
				$remove = true;
				unset($lines[$index]);
			}
			else if ($remove || $tline == '?>') {
				unset($lines[$index]);
			}
		}
		$file = implode('', $lines);
		$file = rtrim($file);

		if (version_compare(VERSION, '3', '<')) {
			$classmapGenerator   = new ClassMapGenerator();
			$classmapClasses     = array();
			$installationManager = $event
				->getComposer()
				->getInstallationManager();
			$localRepository     = $event
				->getComposer()
				->getRepositoryManager()
				->getLocalRepository();
			/** @var PackageInterface $package */
			foreach ($localRepository->getPackages() as $package) {
				if ($package->getType() == self::MODULE_TYPE || $package->getType() == self::LEGACY_MODULE_TYPE) {
					$installPath = $installationManager->getInstallPath($package);
					$autoload    = $package->getAutoload();
					if (array_key_exists('psr-0', $autoload)) {
						foreach ($autoload['psr-0'] as $source) {
							if (file_exists($installPath . DIRECTORY_SEPARATOR . $source)) {
								$classmapClasses = array_merge(
									$classmapClasses,
									$classmapGenerator->createMap($installPath . DIRECTORY_SEPARATOR . $source)
								);
							}
						}
					}
					if (array_key_exists('classmap', $autoload)) {
						foreach ($autoload['classmap'] as $source) {
							if ($installPath . DIRECTORY_SEPARATOR . $source) {
								$classmapClasses = array_merge(
									$classmapClasses,
									$classmapGenerator->createMap($installPath . DIRECTORY_SEPARATOR . $source)
								);
							}
						}
					}
				}
			}
			$classmapClasses = array_keys($classmapClasses);
			$classmapClasses = array_map(
				function ($className) {
					return var_export($className, true);
				},
				$classmapClasses
			);
			$classmapClasses = implode(",\n\t\t", $classmapClasses);

			$file .= <<<EOF


### COMPOSER CLASSES START ###
if (version_compare(VERSION, '3', '<') && class_exists('FileCache')) {
	\$classes = array(
		$classmapClasses
	);
	\$cache = FileCache::getInstance('classes');
	foreach (\$classes as \$class) {
		if (!\$cache->\$class) {
			\$cache->\$class = true;
		}
	}
}
### COMPOSER CLASSES STOP ###


EOF;
		}
		else {
			$file .= "\n";
		}
		file_put_contents($root . '/system/config/localconfig.php', $file);
	}

	public function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateSources(array('copies' => array(), 'links' => array()), $package);
		$this->updateUserfiles($package);
		$this->updateRunonce($package);
	}

	public function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		$map = $this->mapSources($initial);
		parent::updateCode($initial, $target);
		$this->updateSources($map, $target, $initial);
		$this->updateUserfiles($target);
		$this->updateRunonce($target);
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
				$installPath . '/TL_ROOT',
				$sources,
				$package
			);

			$userfiles = array();
			$this->createLegacySourcesSpec(
				$installPath,
				$installPath . '/TL_FILES',
				$installPath . '/TL_FILES',
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
		$sourcePath = str_replace($installPath . DIRECTORY_SEPARATOR, '', $currentPath);
		$targetPath = str_replace($startPath . DIRECTORY_SEPARATOR, '', $currentPath);

		if ($targetPath == 'system/runcone.php') {
			static::$runonces[] = $currentPath;
		}
		else if (is_file($currentPath) || preg_match('#^system/modules/[^/]+$#', $targetPath)) {
			$sources[$sourcePath] = $targetPath;
		}
		else if (is_dir($currentPath)) {
			$files = new \FilesystemIterator($currentPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);

			foreach ($files as $file) {
				$this->createLegacySourcesSpec($installPath, $startPath, $file, $sources, $package);
			}
		}
	}

	protected function mapSources(PackageInterface $package)
	{
		$root    = $this->getContaoRoot($this->composer->getPackage());
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
						\FilesystemIterator::SKIP_DOTS
					)
				);

				/** @var \SplFileInfo $targetFile */
				foreach ($iterator as $targetFile) {
					$pathname = str_replace($root . DIRECTORY_SEPARATOR, '', $targetFile->getRealPath());

					$map['copies'][$source . DIRECTORY_SEPARATOR . str_replace(
						$target . DIRECTORY_SEPARATOR,
						'',
						$pathname
					)] = $pathname;
				}
			}
			else if (is_file($root . DIRECTORY_SEPARATOR . $target)) {
				$map['copies'][$source] = $target;
			}
		}

		return $map;
	}

	protected function updateSources($map, PackageInterface $package, PackageInterface $initial = null)
	{
		$root        = static::getContaoRoot($this->composer->getPackage());
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
							"  - rm link <info>%s</info>",
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
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator(
						$installPath . DIRECTORY_SEPARATOR . $source,
						\FilesystemIterator::SKIP_DOTS
					)
				);

				/** @var \SplFileInfo $sourceFile */
				foreach ($iterator as $sourceFile) {
					$targetPath = $target . DIRECTORY_SEPARATOR . str_replace(
							$installPath . DIRECTORY_SEPARATOR . $source . DIRECTORY_SEPARATOR,
							'',
							$sourceFile->getRealPath()
						);

					if ($this->io->isVerbose()) {
						$this->io->write(
							sprintf(
								"  - cp <info>%s</info>",
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

			$obsolteCopies = array_diff($map['copies'], $copies);
			foreach ($obsolteCopies as $obsolteCopy) {
				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsolteCopy);
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
							"  - rm copy <info>%s</info>",
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
				$linkRel    = str_replace($root . DIRECTORY_SEPARATOR, '', $linkReal);

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

				if (array_key_exists($linkRel, $map['links'])) {
					// link target has changed
					if ($map['links'][$linkRel] != $linkTarget) {
						$this->filesystem->remove($linkReal);
					}
					// link exists and have the correct target
					else {
						continue;
					}
				}

				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							"  - symlink <info>%s</info>",
							$linkRel
						)
					);
				}

				symlink($linkTarget, $linkReal);
				$linkCount++;
			}

			// remove obsolete links
			$obsoleteLinks = array_diff(array_keys($map['links']), $links);
			foreach ($obsoleteLinks as $obsolteLink) {
				if ($this->io->isVerbose()) {
					$this->io->write(
						sprintf(
							"  - rm symlink <info>%s</info>",
							$obsolteLink
						)
					);
				}

				$this->filesystem->remove($root . DIRECTORY_SEPARATOR . $obsolteLink);
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
		$root = static::getContaoRoot($this->composer->getPackage());

		$count = 0;

		// remove symlinks
		foreach ($map['links'] as $link => $target) {
			if ($this->io->isVerbose()) {
				$this->io->write(
					sprintf(
						"  - rm symlink <info>%s</info>",
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
						"  - rm file <info>%s</info>",
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
			$root = static::getContaoRoot($this->composer->getPackage());

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
							"  - rm dir <info>%s</info>",
							str_replace($root, '', $pathname)
						)
					);
				}

				rmdir($pathname);
				$this->removeEmptyDirectories(dirname($pathname));
			}
		}
	}

	public function updateUserfiles(PackageInterface $package)
	{
		$count = 0;

		$extra = $package->getExtra();
		if (array_key_exists('contao', $extra)) {
			$contao = $extra['contao'];

			if (is_array($contao) && array_key_exists('userfiles', $contao)) {
				$root       = static::getContaoRoot($this->composer->getPackage());
				$uploadPath = $GLOBALS['TL_CONFIG']['uploadPath'];

				$userfiles   = (array) $contao['userfiles'];
				$installPath = $this->getInstallPath($package);

				foreach ($userfiles as $source => $target) {
					$target = $uploadPath . DIRECTORY_SEPARATOR . $target;

					$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
					$targetReal = $root . DIRECTORY_SEPARATOR . $target;

					if (is_dir($sourceReal))
					{

						$it = new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS);
						$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

						if (!file_exists($targetReal)) {
							$this->filesystem->ensureDirectoryExists($targetReal);
						}

						foreach ($ri as $file) {
							$targetPath = $targetReal . DIRECTORY_SEPARATOR . $ri->getSubPathName();
							if (!file_exists($targetPath)) {
								if ($file->isDir()) {
									$this->filesystem->ensureDirectoryExists($targetPath);
								}
								else {
									if ($this->io->isVerbose()) {
										$this->io->write(
											sprintf(
											'  - install userfile <info>%s</info>',
											$ri->getSubPathName()
											)
										);
									}
									copy($file->getPathname(), $targetPath);
									$count++;
								}
							}
						}
					} else {
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

	public function updateRunonce(PackageInterface $package)
	{
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

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType)
	{
		return self::MODULE_TYPE === $packageType || self::LEGACY_MODULE_TYPE == $packageType;
	}
}
