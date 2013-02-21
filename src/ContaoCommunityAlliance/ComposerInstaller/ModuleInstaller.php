<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Version\VersionParser;

class ModuleInstaller extends LibraryInstaller
{
	static public function updateContaoPackage(\Composer\Script\Event $event)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());
			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		// Contao 3+
		if (file_exists($root . '/system/config/constants.php')) {
			require_once($root . '/system/config/constants.php');
		}
		// Contao 2+
		else if (file_exists($root . '/system/constants.php')) {
			require_once($root . '/system/constants.php');
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

    public function getInstallPath(PackageInterface $package)
    {
        $extra = $package->getExtra();

        if(!array_key_exists('contao', $extra))
        {
            throw new \ErrorException("A contao-module needs the contao declaration within the extra block!");
        }

        $contao = $extra['contao'];

        if(!array_key_exists('target', $contao) && !array_key_exists('targets', $contao))
        {
            throw new \ErrorException("Please add a target or targets key to the contao section, { target: \"_composer\" } for example (folder name in contao)!");
        }

        if(array_key_exists('target', $contao) && array_key_exists('targets', $contao))
        {
            throw new \ErrorException("You can not combine target and targets key in the contao section!");
        }

		if (!array_key_exists('targets', $contao))
		{
        	return '../system/modules/' . $contao['target'];
		}

		return parent::getInstallPath($package);
    }

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateTargets($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		parent::updateCode($initial, $target);
		$this->removeTargets($initial);
		$this->updateTargets($target);
	}

	protected function removeCode(PackageInterface $package)
	{
		parent::removeCode($package);
		$this->removeTargets($package);
	}

	protected function updateTargets(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];

		if (array_key_exists('targets', $contao)) {
			$targets = $contao['targets'];
			$installPath = $this->getInstallPath($package);

			foreach ($targets as $source => $target) {
				$this->io->write("  - Copy target <info>" . $source . "</info> to <info>" . $target . "</info> from package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

				$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
				$targetReal = '../' . $target;

				$it = new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS);
				$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

				if ( !file_exists($targetReal)) {
					mkdir($targetReal, 0777, true);
				}
				else {
					$this->io->write("  - Skip target <info>" . $source . "</info> because <info>" . $target . "</info> already exists");
					continue;
				}

				foreach ($ri as $file) {
					$targetPath = $targetReal . DIRECTORY_SEPARATOR . $ri->getSubPathName();
					if ($file->isDir()) {
						mkdir($targetPath);
					} else {
						copy($file->getPathname(), $targetPath);
					}
				}
			}
		}
	}

	protected function removeTargets(PackageInterface $package)
	{
		$extra = $package->getExtra();
		$contao = $extra['contao'];

		if (array_key_exists('targets', $contao)) {
			$targets = $contao['targets'];

			foreach ($targets as $path) {
				$this->io->write("  - Removing <info>" . $path . "</info> from package <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

				$path = '../' . $path;

				if (!$this->filesystem->removeDirectory($path)) {
					// retry after a bit on windows since it tends to be touchy with mass removals
					if (!defined('PHP_WINDOWS_VERSION_BUILD') || (usleep(250) && !$this->filesystem->removeDirectory($path))) {
						throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
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
        return 'contao-module' === $packageType;
    }
}