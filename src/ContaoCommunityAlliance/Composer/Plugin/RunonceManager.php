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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;

/**
 * Remember runonce files that found while installing packages and
 * finally create a single TL_ROOT/system/runonce.php file.
 */
class RunonceManager
{
    /**
     * List of runonce files that was found in the installed/updated packages.
     *
     * @var array
     */
    static public $runonces = array();

    /**
     * Create the global runonce file TL_ROOT/system/runonce.php file if required.
     *
     * @param IOInterface $inputOutput The composer io stream.
     *
     * @param string      $root        The Contao installation root path.
     *
     * @return void
     */
    public static function createRunonce(IOInterface $inputOutput, $root)
    {
        // create runonce
        $runonces = array_unique(static::$runonces);
        if (count($runonces)) {
            $file   = 'system/runonce.php';
            $buffer = '';
            $index  = 0;
            while (file_exists($root . DIRECTORY_SEPARATOR . $file)) {
                $buffer .= file_get_contents($root . DIRECTORY_SEPARATOR . $file);
                $index++;
                $file = 'system/runonce_' . $index . '.php';
            }
            if ($index > 0) {
                rename(
                    $root . '/system/runonce.php',
                    $root . DIRECTORY_SEPARATOR . $file
                );
                array_unshift(
                    $runonces,
                    $file
                );
            }

            // Filter the runonces.
            $filtered = array();
            foreach ($runonces as $runonce) {
                if (strpos($buffer, $runonce) !== false) {
                    $inputOutput->write(
                        sprintf(
                            '<info>Not adding runonce %s, already mentioned in existing runonce file.</info>',
                            $runonce
                        )
                    );
                    continue;
                }
                $filtered[] = $runonce;
            }

            $array = var_export($filtered, true);

            $runonce = <<<EOF
<?php

\$executor = new \ContaoCommunityAlliance\Composer\Plugin\RunonceExecutor();
\$executor->run($array);

EOF;
            file_put_contents($root . '/system/runonce.php', $runonce);
            static::$runonces = array();

            $inputOutput->write(
                sprintf(
                    '<info>Runonce created with %d updates</info>',
                    count($runonces)
                )
            );
            if ($inputOutput->isVerbose()) {
                foreach ($runonces as $runonce) {
                    $inputOutput->write('  - ' . $runonce);
                }
            }
        }
    }

    /**
     * Add a runonce file by path.
     *
     * @param string $path The absolute runonce file path.
     *
     * @return void
     */
    public static function addRunonce($path)
    {
        static::$runonces[] = $path;
    }

    /**
     * Check if the file is contained within the given pathes.
     *
     * @param string   $file   The file to check.
     *
     * @param string[] $pathes The pathes to check against.
     *
     * @return bool
     */
    public static function checkIsInInstallPathes($file, $pathes)
    {
        foreach ($pathes as $path) {
            if (strncmp($file, $path, strlen($path)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file is installed.
     *
     * @param string           $file    The filename.
     *
     * @param PackageInterface $package The package to check against.
     *
     * @return bool
     */
    public static function isInstalledFile($file, PackageInterface $package)
    {
        // Root runonce, definately getting called from Contao.
        if (preg_match('#system/runonce.php#', $file)) {
            return true;
        }

        // Module config runonce, also getting called from Contao.
        if (!preg_match('#system/modules/[^/]*/config/runonce.php#', $file)) {
            return false;
        }

        $extra = $package->getExtra();

        if (isset($extra['contao']['shadow-copies'])
            && static::checkIsInInstallPathes($file, $extra['contao']['shadow-copies'])) {
            return true;
        } elseif (isset($extra['contao']['symlinks'])
            && static::checkIsInInstallPathes($file, $extra['contao']['symlinks'])) {
            return true;
        } elseif (isset($extra['contao']['sources'])
            && static::checkIsInInstallPathes($file, $extra['contao']['sources'])) {
            return true;
        }

        return false;
    }

    /**
     * Add runonce files from a package.
     *
     * @param PackageInterface $package     The package to retrieve runonce files from.
     *
     * @param string           $installPath The installation path.
     *
     * @return void
     */
    public static function addRunonces(PackageInterface $package, $installPath)
    {
        static::checkDuplicateInstallation($package);

        $extra = $package->getExtra();
        if (isset($extra['contao']['runonce'])) {
            $runonces = (array) $extra['contao']['runonce'];

            foreach ($runonces as $file) {
                if (!static::isInstalledFile($file, $package)) {
                    static::addRunonce($installPath . DIRECTORY_SEPARATOR . $file);
                }
            }
        }
    }

    /**
     * Update all runonce files from all installed packages.
     *
     * @param Composer $composer The composer instance.
     *
     * @return void
     */
    public static function addAllRunonces(Composer $composer)
    {
        $installationManager = $composer->getInstallationManager();
        $repositoryManager   = $composer->getRepositoryManager();
        $localRepository     = $repositoryManager->getLocalRepository();
        $packages            = $localRepository->getPackages();

        /** @var PackageInterface $package */
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                $installer = $installationManager->getInstaller($package->getType());
                static::addRunonces($package, $installer->getInstallPath($package));
            }
        }
    }

    /**
     * Retrieve the collected runonce files.
     *
     * @return array
     */
    public static function getRunonces()
    {
        return static::$runonces;
    }

    /**
     * Clear the runonces.
     *
     * @return void
     */
    public static function clearRunonces()
    {
        static::$runonces = array();
    }

    /**
     * Ugly hack to check duplicate installation after plugin update.
     *
     * @param PackageInterface $package The package to check.
     *
     * @return void
     *
     * @throws DuplicateContaoException When the Contao core has been found.
     */
    private static function checkDuplicateInstallation(PackageInterface $package)
    {
        if ($package->getName() === 'contao/core' || in_array($package->getName(), Environment::$bundleNames)) {
            $roots = Environment::findContaoRoots();

            if (count($roots) > 1) {
                throw new DuplicateContaoException(
                    'Warning: Contao core was installed but has been found in project root, ' .
                    'to recover from this problem please restart the operation'
                );
            }
        }
    }
}
