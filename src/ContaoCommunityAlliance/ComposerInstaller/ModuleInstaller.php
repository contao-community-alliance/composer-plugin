<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;

class ModuleInstaller extends LibraryInstaller
{
	static protected $runonces = array();

	static public function updateContaoPackage(Event $event)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());
			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		// Contao 3+
		if (file_exists($constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'constants.php')) {
			require_once($constantsFile);
		}
		// Contao 2+
		else if (file_exists($constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'constants.php')) {
			require_once($constantsFile);
		}
		else {
			throw new \Exception('Could not find constants.php in ' . $root);
		}

		$composer = $event->getComposer();

		/** @var \Composer\Package\RootPackage $package */
		$package = $composer->getPackage();

		$versionParser = new VersionParser();

		$version = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);

		if ($package->getVersion() !== $prettyVersion) {
			$json = file_get_contents('composer.json');
			$json = preg_replace(
				'#("version"\s*:\s*)"\d+(\.\d+)*"#',
				'$1' . $version,
				$json,
				1
			);
			file_put_contents('composer.json', $json);

			$io = $event->getIO();
			$io->write("Contao version changed from <info>" . $package->getPrettyVersion() . "</info> to <info>" . $version . "</info>, please restart composer");
			exit;
		}
	}

	static public function createRunonce(Event $event)
	{
		if (count(static::$runonces)) {
			$file = 'system/runonce.php';
			$n = 0;
			while (file_exists('..' . DIRECTORY_SEPARATOR . $file)) {
				$n ++;
				$file = 'system/runonce_' . $n . '.php';
			}
			if ($n > 0) {
				rename(
					'../system/runonce.php',
					'..' . DIRECTORY_SEPARATOR . $file
				);
				array_unshift(
					static::$runonces,
					$file
				);
			}

			$template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'RunonceExecutorTemplate.php');
			$template = str_replace(
				'TEMPLATE_RUNONCE_ARRAY',
				var_export(static::$runonces, true),
				$template
			);
			file_put_contents('../system/runonce.php', $template);

			$io = $event->getIO();
			$io->write("<info>Runonce created with " . count(static::$runonces) . " updates</info>");
			foreach (static::$runonces as $runonce) {
				$io->write("  - " . $runonce);
			}
		}
	}

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateSymlinks($package);
		$this->updateUserfiles($package);
		$this->updateRunonce($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		parent::updateCode($initial, $target);
		$this->updateSymlinks($target, $initial);
		$this->updateUserfiles($target);
		$this->updateRunonce($target);
	}

	protected function removeCode(PackageInterface $package)
	{
		parent::removeCode($package);
		$this->removeSymlinks($package);
	}

	protected function calculateSymlinkMap(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];
		$map = array();

		if (!is_array($contao)) {
			return;
		}
		if (!array_key_exists('symlinks', $contao)) {
			$contao['symlinks'] = array();
		}

		$symlinks = (array) $contao['symlinks'];

		// symlinks disabled
		if ($symlinks === false) {
			return array();
		}

		// add fallback symlink
		if (empty($symlinks)) {
			$symlinks[''] = 'system/modules/' . preg_replace('#^.*/#', '', $package->getName());
		}

		$installPath = $this->getInstallPath($package);

		foreach ($symlinks as $target => $link) {
			$targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
			$linkReal = realpath('..') . DIRECTORY_SEPARATOR . $link;

			if (file_exists($linkReal)) {
				if (!is_link($linkReal)) {
					// special behavior for composer extension
					if ($package->getName() == 'contao-community-alliance/composer') {
						$this->filesystem->removeDirectory('../system/modules/_composer');
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
			$linkParts = array_values(
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
			for ($i=1; $i<$n; $i++) {
				$linkTargetParts[] = '..';
			}

			$linkTargetParts = array_merge(
				$linkTargetParts,
				$targetParts
			);

			$linkTarget = implode(DIRECTORY_SEPARATOR, $linkTargetParts);

			$map[$linkReal] = $linkTarget;
		}


		return $map;
	}

	protected function updateSymlinks(PackageInterface $package, PackageInterface $initial = null)
	{
		$map = $this->calculateSymlinkMap($package);

		$root = dirname(getcwd());

		if ($initial) {
			$previousMap = $this->calculateSymlinkMap($initial);

			$obsoleteLinks = array_diff(
				array_keys($previousMap),
				array_keys($map)
			);

			foreach ($obsoleteLinks as $linkReal) {
				if (is_link($linkReal)) {
					$this->io->write("  - Remove symlink <info>" . str_replace($root, '', $linkReal) . "</info> to <info>" . readlink($linkReal) . "</info> for package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
					unlink($linkReal);
				}
			}
		}

		foreach ($map as $linkReal => $linkTarget) {
			if (!is_link($linkReal) || readlink($linkReal) != $linkTarget) {
				if (is_link($linkReal)) {
					unlink($linkReal);
				}
				$this->io->write("  - Create symlink <info>" . str_replace($root, '', $linkReal) . "</info> to <info>" . $linkTarget . "</info> for package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
				symlink($linkTarget, $linkReal);
			}
		}
	}

	protected function removeSymlinks(PackageInterface $package)
	{
		$map = $this->calculateSymlinkMap($package);

		$root = dirname(getcwd());

		foreach ($map as $linkReal => $linkTarget) {
			if (is_link($linkReal)) {
				$this->io->write("  - Remove symlink <info>" . str_replace($root, '', $linkReal) . "</info> to <info>" . readlink($linkReal) . "</info> for package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
				unlink($linkReal);
			}
		}
	}

	protected function updateUserfiles(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];

		if (is_array($contao) && array_key_exists('userfiles', $contao)) {
			$root = dirname(getcwd());
			$configDir = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
			require_once($configDir . 'config.php');
			require_once($configDir . 'localconfig.php');

			$uploadPath = $GLOBALS['TL_CONFIG']['uploadPath'];

			$userfiles = (array) $contao['userfiles'];
			$installPath = $this->getInstallPath($package);

			foreach ($userfiles as $source => $target) {
				$target = $uploadPath . DIRECTORY_SEPARATOR . $target;

				$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
				$targetReal = '..' . DIRECTORY_SEPARATOR . $target;

				$it = new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS);
				$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

				if ( !file_exists($targetReal)) {
					mkdir($targetReal, 0777, true);
				}

				foreach ($ri as $file) {
					$targetPath = $targetReal . DIRECTORY_SEPARATOR . $ri->getSubPathName();
					if (!file_exists($targetPath)) {
						if ($file->isDir()) {
							mkdir($targetPath);
						} else {
							$this->io->write("  - Copy userfile <info>" . $ri->getSubPathName() . "</info> to <info>" . $target . DIRECTORY_SEPARATOR . $ri->getSubPathName() . "</info> from package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
							copy($file->getPathname(), $targetPath);
						}
					}
				}
			}
		}
	}

	protected function updateRunonce(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];

		if (is_array($contao) && array_key_exists('runonce', $contao)) {
			$root = dirname(getcwd()) . DIRECTORY_SEPARATOR;
			$runonces = (array) $contao['runonce'];

			$installPath = str_replace($root, '', $this->getInstallPath($package));

			foreach ($runonces as $file) {
				static::$runonces[] = $installPath . DIRECTORY_SEPARATOR . $file;
			}
		}
	}

	/**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'contao-module' === $packageType;
    }
}