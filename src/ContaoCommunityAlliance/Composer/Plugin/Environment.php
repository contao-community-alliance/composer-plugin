<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Package\RootPackageInterface;
use RuntimeException;

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
     * @param RootPackageInterface $package
     *
     * @return array
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

            if (!empty($extra['contao']['root']) && static::isContao($cwd)) {
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
     * Returns wether the given folder contains a Contao installation.
     *
     * @param string $path
     *
     * @return bool
     */
    public static function isContao($path)
    {
        return is_dir($path . DIRECTORY_SEPARATOR . 'system');
    }
}
