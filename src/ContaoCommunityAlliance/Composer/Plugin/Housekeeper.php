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
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * Handy class containing clean up methods to clean obsolete stuff from a contao installation.
 *
 * @package ContaoCommunityAlliance\Composer\Plugin
 */
class Housekeeper
{
    /**
     * Clean the internal cache of Contao after updates has been installed.
     *
     * @param IOInterface $inputOutput The input output interface to use.
     *
     * @param string      $root        The contao installation root.
     *
     * @return void
     *
     * @throws \RuntimeException When the root path is a windows drive root.
     *
     * @throws RuntimeException When an OS error occurred while deleting.
     */
    public static function cleanCache(IOInterface $inputOutput, $root)
    {
        // clean cache
        $filesystem = new Filesystem();
        foreach (array('config', 'dca', 'language', 'sql') as $dir) {
            $cache = $root . '/system/cache/' . $dir;
            if (is_dir($cache)) {
                $inputOutput->write(
                    sprintf(
                        '<info>Clean contao internal %s cache</info>',
                        $dir
                    )
                );
                $filesystem->removeDirectory($cache);
            }
        }
    }

    /**
     * Remove obsolete class loader content from localconfig.php.
     *
     * @param IOInterface $inputOutput The input output interface to use.
     *
     * @param string      $root        The contao installation root.
     *
     * @return void
     */
    public static function cleanLocalConfig(IOInterface $inputOutput, $root)
    {
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
                } elseif ($tline == '### COMPOSER CLASSES STOP ###') {
                    $remove = false;
                    unset($lines[$index]);
                } elseif ($remove || $tline == '?>') {
                    unset($lines[$index]);
                }
            }

            if ($modified) {
                $file = implode('', $lines);
                $file = rtrim($file);

                file_put_contents($root . '/system/config/localconfig.php', $file);

                $inputOutput->write(
                    '<info>Removed obsolete class loader cache from localconfig.php</info>'
                );
            }
        }
    }
}
