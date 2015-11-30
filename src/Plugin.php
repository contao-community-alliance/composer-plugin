<?php

/**
 * Contao Composer Plugin
 *
 * Copyright (C) 2013-2015 Contao Community Alliance
 *
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
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
 *
 * @author Andreas Schempp <https://github.com/aschempp>
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
     * The input output interface.
     *
     * @var IOInterface
     */
    protected $io;

    /**
     * @var RunonceManager
     */
    private $runonceManager;

    /**
     * Constructor.
     *
     * @param RunonceManager $runonceManager
     */
    public function __construct(RunonceManager $runonceManager = null)
    {
        $this->runonceManager = $runonceManager;
    }

    /**
     * Activate the composer plugin. This methods is called by Composer to initialize plugins.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;

        if (null === $this->runonceManager) {
            $this->runonceManager = new RunonceManager(
                dirname($composer->getConfig()->get('vendor-dir')) . '/app/Resources/contao/config/runonce.php'
            );
        }

        $installationManager = $composer->getInstallationManager();

        $installationManager->addInstaller(
            new ContaoModuleInstaller($this->runonceManager, $io, $composer)
        );

        $installationManager->addInstaller(
            new LegacyContaoModuleInstaller($this->runonceManager, $io, $composer)
        );
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
     */
    public function dumpRunonce()
    {
        $this->runonceManager->dump();
    }
}
