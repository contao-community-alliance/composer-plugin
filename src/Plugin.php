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
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Oliver Hoff <oliver@hofff.com>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use ContaoCommunityAlliance\Composer\Plugin\Installer\ContaoModuleInstaller;
use ContaoCommunityAlliance\Composer\Plugin\Installer\LegacyContaoModuleInstaller;

/**
 * The Composer plugin registers our installers for Contao modules.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The composer instance.
     *
     * @var Composer
     */
    protected $composer;

    /**
     * The run once manager in use.
     *
     * @var RunonceManager
     */
    private $runonceManager;

    /**
     * If root package is not a project, the plugin will not install files.
     *
     * @var bool
     */
    private $isProject = true;

    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager The run once manager to use.
     */
    public function __construct(RunonceManager $runonceManager = null)
    {
        $this->runonceManager = $runonceManager;
    }

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $inputOutput)
    {
        if (!\in_array($composer->getPackage()->getType(), ['project', 'contao-theme'], true)) {
            $this->isProject = false;
            $inputOutput->writeError(
                'Root package is not of type "project" or "contao-theme", we will not be installing Contao extensions.'
            );
            return;
        }

        $this->composer = $composer;

        if (null === $this->runonceManager) {
            $rootDir = getcwd() . '/';
            $extras  = $composer->getPackage()->getExtra();

            if (isset($extras['symfony-app-dir']) && is_dir($rootDir . $extras['symfony-app-dir'])) {
                $rootDir .= trim($extras['symfony-app-dir'], '/');
            } else {
                $rootDir .= 'app';
            }

            $this->runonceManager = new RunonceManager(
                $rootDir . '/Resources/contao/config/runonce.php'
            );
        }

        $installationManager = $composer->getInstallationManager();

        $installationManager->addInstaller(
            new ContaoModuleInstaller($this->runonceManager, $inputOutput, $composer)
        );

        $installationManager->addInstaller(
            new LegacyContaoModuleInstaller($this->runonceManager, $inputOutput, $composer)
        );
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // does nothing
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // does nothing
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     * The array keys are event names and the value can be:
     * * The method name to call (priority defaults to 0)
     * * An array composed of the method name to call and the priority
     * * An array of arrays composed of the method names to call and respective
     *   priorities, or 0 if unset
     * For instance:
     * * array('eventName' => 'methodName')
     * * array('eventName' => array('methodName', $priority))
     * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {

        return [
            ScriptEvents::POST_INSTALL_CMD => 'dumpRunonce',
            ScriptEvents::POST_UPDATE_CMD  => 'dumpRunonce',
        ];
    }

    /**
     * Dump runonce file after install or update command.
     *
     * @return void
     */
    public function dumpRunonce()
    {
        if (!$this->isProject) {
            return;
        }

        $this->runonceManager->dump();
    }
}
