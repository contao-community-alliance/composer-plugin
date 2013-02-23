<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use Composer\Script\CommandEvent;

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

	static public function createRunonce(CommandEvent $event)
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
				'',
				var_export(static::$runonces, true),
				$template
			);
			file_put_contents('../system/runonce.php', $template);

			$io = $event->getIO();
			$io->write("  - Runonce created with " . count(static::$runonces) . " updates");
		}
	}

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateSymlinks($package);
		$this->updateUserfiles($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		parent::updateCode($initial, $target);
		$this->updateSymlinks($target, $initial);
		$this->updateUserfiles($target);
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

		if (array_key_exists('symlinks', $contao)) {
			$symlinks = (array) $contao['symlinks'];

			if (empty($symlinks)) {
				$symlinks[''] = 'system/modules/' . $package->getName();
			}

			$installPath = $this->getInstallPath($package);

			foreach ($symlinks as $target => $link) {
				$targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
				$linkReal = realpath('..' . DIRECTORY_SEPARATOR . $target);

				if (file_exists($linkReal)) {
					if (!is_link($linkReal)) {
						throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
					}
				}

				$targetParts = array_filter(explode(DIRECTORY_SEPARATOR, $targetReal));
				$linkParts = array_filter(explode(DIRECTORY_SEPARATOR, $linkReal));

				// calculate a relative link target
				$linkTargetParts = array();

				while (count($targetParts) && count($linkParts) && $targetParts[0] == $linkParts[0]) {
					array_shift($targetParts);
					array_shift($linkParts);

					$n = count($linkParts);
					for ($i=0; $i<$n; $i++) {
						$linkTargetParts[] = '..';
					}
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
		$map = $this->calculateSymlinkMap($package);

		if ($initial) {
			$previousMap = $this->calculateSymlinkMap($initial);

			$obsoleteLinks = array_diff(
				array_keys($previousMap),
				array_keys($map)
			);

			foreach ($obsoleteLinks as $linkReal) {
				unlink($linkReal);
			}
		}

		foreach ($map as $linkReal => $linkTarget) {
			if (!file_exists($linkReal) || readlink($linkReal) != $linkTarget) {
				if (file_exists($linkReal)) {
					unlink($linkReal);
				}
				symlink($linkTarget, $linkReal);
			}
		}
	}

	protected function updateUserfiles(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];

		if (array_key_exists('userfiles', $contao)) {
			$configDir = TL_ROOT . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
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

		if (array_key_exists('runonce', $contao)) {
			$runonces = (array) $contao['runonce'];

			$installPath = $this->getInstallPath($package);

			foreach ($runonces as $file) {
				static::$runonces[] = 'composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . $installPath . DIRECTORY_SEPARATOR . $file;
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