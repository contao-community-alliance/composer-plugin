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
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Package\RootPackageInterface;
use RuntimeException;

/**
 * This class provides static methods to check for the Contao installation root.
 */
class Environment
{
    /**
     * The bundle names the virtual core shall provide.
     *
     * NOTE: 'contao/installation-bundle' is a special case and will not get added here, as it is new code only.
     *
     * @var string[]
     */
    public static $bundleNames = array(
        'contao/calendar-bundle',
        'contao/comments-bundle',
        'contao/core-bundle',
        'contao/faq-bundle',
        'contao/listing-bundle',
        'contao/news-bundle',
        'contao/newsletter-bundle',
    );

    /**
     * Returns a list of Contao paths.
     * Multiple paths mean there's likely a problem with the installation (e.g. Contao in root and vendor folder).
     *
     * @param RootPackageInterface $package The package to check if a root has been specified in the extra section.
     *
     * @return array
     *
     * @throws RuntimeException When the current working directory could not be determined.
     */
    public static function findContaoRoots(RootPackageInterface $package = null)
    {
        $roots = array();
        $cwd   = getcwd();

        if (!$cwd) {
            throw new RuntimeException('Could not determine current working directory.');
        }

        // Check if we have a Contao installation in the current working dir. See #15.
        if (static::isContao($cwd)) {
            $roots['root'] = $cwd;
        }

        if (static::isContao(dirname($cwd))) {
            $roots['parent'] = dirname($cwd);
        }

        if (null !== $package) {
            $extra = $package->getExtra();

            if (!empty($extra['contao']['root'])
                && static::isContao($cwd . DIRECTORY_SEPARATOR . $extra['contao']['root'])
            ) {
                $roots['extra'] = $cwd . DIRECTORY_SEPARATOR . $extra['contao']['root'];
            }
        }

        // test, do we have the core within vendor/contao/core.
        $vendorRoot = $cwd . DIRECTORY_SEPARATOR .
            'vendor' . DIRECTORY_SEPARATOR .
            'contao' . DIRECTORY_SEPARATOR .
            'core';

        if (static::isContao($vendorRoot)) {
            $roots['vendor'] = $vendorRoot;
        }

        return $roots;
    }

    /**
     * Returns whether the given folder contains a Contao installation.
     *
     * @param string $path The path to check.
     *
     * @return bool
     */
    public static function isContao($path)
    {
        return file_exists($path . DIRECTORY_SEPARATOR . 'system/config/constants.php')
            || file_exists($path . DIRECTORY_SEPARATOR . 'system/constants.php');
    }
}
