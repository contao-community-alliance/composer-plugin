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
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\RunonceManager;

/**
 * ContaoModuleInstaller installs Composer packages of type "contao-module".
 * These are the Contao modules available on Packagist.
 *
 * @author Andreas Schempp <https://github.com/aschempp>
 */
class ContaoModuleInstaller extends AbstractModuleInstaller
{
    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager The run once manager to use.
     *
     * {@inheritdoc}
     */
    // @codingStandardsIgnoreStart - Overriding this method is not useless, we add a parameter default here.
    public function __construct(
        RunonceManager $runonceManager,
        IOInterface $inputOutput,
        Composer $composer,
        $type = 'contao-module',
        Filesystem $filesystem = null
    // @codingStandardsIgnoreEnd
    ) {
        parent::__construct($runonceManager, $inputOutput, $composer, $type, $filesystem);
    }

    /**
     * {@inheritDoc}
     */
    protected function getSources(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'sources') ?: [];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUserFiles(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'userfiles') ?: [];
    }

    /**
     * {@inheritDoc}
     */
    protected function getRunonces(PackageInterface $package)
    {
        return $this->getContaoExtra($package, 'runonce') ?: [];
    }

    /**
     * Retrieves a value from the package extra "contao" section.
     *
     * @param PackageInterface $package The package to extract the section from.
     *
     * @param string           $key     The key to obtain from the extra section.
     *
     * @return mixed|null
     */
    private function getContaoExtra(PackageInterface $package, $key)
    {
        $extras = $package->getExtra();

        if (!isset($extras['contao']) || !isset($extras['contao'][$key])) {
            return null;
        }

        return $extras['contao'][$key];
    }
}
