<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @author  Oliver Hoff <oliver@hofff.com>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Json\JsonFile;
use Composer\Package\Version\VersionParser;

/**
 * Manipulate the root composer.json on the fly.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ConfigManipulator
{
    /**
     * Run all configuration updates.
     *
     * @throws ConfigUpdateException When the upgrade process did perform any action. The process should be restarted.
     *
     * @return void
     *
     * @throws ConfigUpdateException For all performed actions.
     */
    public static function run()
    {
        $messages   = array();
        $configFile = new JsonFile('composer.json');
        $configJson = $configFile->read();

        // NOTE: we do not need our hard-coded scripts anymore, since we have a plugin

        $jsonModified = static::runUpdates($configJson, $messages);

        if ($jsonModified) {
            copy('composer.json', 'composer.json~');
            $configFile->write($configJson);
        }
        if (count($messages)) {
            $exception = null;
            foreach (array_reverse($messages) as $message) {
                $exception = new ConfigUpdateException($message, 0, $exception);
            }
            throw $exception;
        }
    }

    /**
     * Run the updates on the given config.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function runUpdates(
        &$configJson,
        &$messages
    ) {
        $jsonModified = false;

        $jsonModified = static::removeObsoleteScripts($configJson, $messages) || $jsonModified;
        $jsonModified = static::removeObsoleteConfigEntries($configJson, $messages) || $jsonModified;
        $jsonModified = static::removeObsoleteProvides($configJson, $messages) || $jsonModified;
        $jsonModified = static::removeObsoleteContaoVersion($configJson, $messages) || $jsonModified;
        $jsonModified = static::updateRequirements($configJson, $messages) || $jsonModified;
        $jsonModified = static::restoreRepositories($configJson, $messages) || $jsonModified;
        $jsonModified = static::restoreNeededConfigKeys($configJson, $messages) || $jsonModified;

        // @codingStandardsIgnoreStart
        // TODO we need a new contao version change check!!!
        /*
        if ($contaoVersionUpdated) {
            // run all runonces after contao version changed
            RunonceManager::addAllRunonces($composer);
            RunonceManager::createRunonce($inputOutput, $root);
        }
        */
        // @codingStandardsIgnoreEnd

        return $jsonModified;
    }

    /**
     * Remove obsolete event scripts from the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function removeObsoleteScripts(&$configJson, &$messages)
    {
        $jsonModified = false;

        // remove old installer scripts
        $eventScripts = array(
            'pre-update-cmd'     => array(
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateContaoPackage',
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateComposerConfig',
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
            ),
            'post-update-cmd'    => array(
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::createRunonce',
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate',
            ),
            'post-autoload-dump' => array(
                'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
            ),
        );
        foreach ($eventScripts as $key => $scripts) {
            foreach ($scripts as $script) {
                $jsonModified = static::removeObsoleteScript($key, $script, $configJson, $messages) ||
                    $jsonModified;
            }
        }

        if (isset($configJson['scripts']) && empty($configJson['scripts'])) {
            unset($configJson['scripts']);
            $jsonModified = true;
        }

        return $jsonModified;
    }

    /**
     * Remove obsolete event script.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param string $key        The key under which the script is registered.
     *
     * @param string $script     The script to remove.
     *
     * @param array  $configJson The json config (composer.json).
     *
     * @param array  $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function removeObsoleteScript($key, $script, &$configJson, &$messages)
    {
        if (isset($configJson['scripts'][$key])) {
            if (is_array($configJson['scripts'][$key])) {
                $index = array_search($script, $configJson['scripts'][$key]);
                if ($index !== false) {
                    unset($configJson['scripts'][$key][$index]);
                    if (empty($configJson['scripts'][$key])) {
                        unset($configJson['scripts'][$key]);
                    }

                    $messages[] = 'obsolete ' . $key . ' script ' . $script .
                        ' was removed from root composer.json';
                    return true;
                }
            } elseif ($configJson['scripts'][$key] == $script) {
                unset($configJson['scripts'][$key]);

                $messages[] = 'obsolete ' . $key . ' script ' . $script .
                    ' was removed from root composer.json';
                return true;
            }
        }

        return false;
    }

    /**
     * Remove obsolete configuration entries from the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function removeObsoleteConfigEntries(&$configJson, &$messages)
    {
        $jsonModified = false;

        if (isset($configJson['extra']['contao']['artifactPath'])) {
            unset($configJson['extra']['contao']['artifactPath']);

            $jsonModified = true;
            $messages[]   = 'obsolete config entry { extra: { contao: { artifactPath: ... } } } ' .
                'was removed from root composer.json';
        }

        return $jsonModified;
    }

    /**
     * Remove obsolete provide entries from the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function removeObsoleteProvides(&$configJson, &$messages)
    {
        $jsonModified = false;

        if (
            isset($configJson['name']) &&
            isset($configJson['type']) &&
            ($configJson['name'] == 'contao/core') &&
            ($configJson['type'] == 'metapackage') &&
            isset($configJson['provide']['swiftmailer/swiftmailer'])) {
            unset($configJson['provide']['swiftmailer/swiftmailer']);

            $messages[] = '"swiftmailer/swiftmailer" has been removed from provide section ' .
                'in root composer.json!';

            $jsonModified = true;

            if (empty($configJson['provide'])) {
                unset($configJson['provide']);
            }
        }

        return $jsonModified;
    }

    /**
     * Remove the Contao Version and additional information from the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function removeObsoleteContaoVersion(&$configJson, &$messages)
    {
        $jsonModified = false;

        if (
            isset($configJson['name']) &&
            isset($configJson['type']) &&
            ($configJson['name'] == 'contao/core') &&
            ($configJson['type'] == 'metapackage')
        ) {
            $configJson['name'] = 'local/website';
            $messages[]         = 'name has been changed to "local/website" in root composer.json!';
            $configJson['type'] = 'project';
            $messages[]         = 'type has been changed to "project" in root composer.json!';

            $jsonModified = true;
            $messages[]   = 'obsolete contao version and meta information ' .
                'was removed from root composer.json!';
        }

        return $jsonModified;
    }

    /**
     * Update requires in the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function updateRequirements(&$configJson, &$messages)
    {
        if (isset($configJson['type']) && $configJson['type'] === 'contao-module') {
            return false;
        }

        $jsonModified = false;

        // remove contao-community-alliance/composer dependency
        if (isset($configJson['require']['contao-community-alliance/composer'])) {
            if (isset($configJson['require']['contao-community-alliance/composer-client'])) {
                unset($configJson['require']['contao-community-alliance/composer']);

                $jsonModified = true;
                $messages[]   = 'obsolete require contao-community-alliance/composer ' .
                                'was removed from root composer.json';
            } else {
                $configJson['require']['contao-community-alliance/composer-client'] =
                    $configJson['require']['contao-community-alliance/composer'];
                unset($configJson['require']['contao-community-alliance/composer']);

                $jsonModified = true;
                $messages[]   = 'require contao-community-alliance/composer was upgraded to ' .
                                'contao-community-alliance/composer-client in root composer.json';
            }
        }

        // add contao-community-alliance/composer-client dependency
        if (!isset($configJson['require']['contao-community-alliance/composer-client'])) {
            $configJson['require']['contao-community-alliance/composer-client'] = '~0.14';

            $jsonModified = true;
            $messages[]   = 'require contao-community-alliance/composer-client ' .
                'was added to root composer.json';
        }

        // upgrade version
        if ('dev-' !== substr($configJson['require']['contao-community-alliance/composer-client'], 0, 4)) {
            $versionParser   = new VersionParser();
            $requiredVersion = $versionParser->parseConstraints(
                $configJson['require']['contao-community-alliance/composer-client']
            );
            if (!$requiredVersion->matches($versionParser->parseConstraints('>=0.14-dev'))) {
                $configJson['require']['contao-community-alliance/composer-client'] = '~0.14';

                $jsonModified = true;
                $messages[]   = 'require contao-community-alliance/composer-client ' .
                                'was changed to ~0.14 in root composer.json';
            }
        }

        return $jsonModified;
    }

    /**
     * Restore repositories in the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function restoreRepositories(&$configJson, &$messages)
    {
        if (isset($configJson['type']) && $configJson['type'] === 'contao-module') {
            return false;
        }

        if (!isset($configJson['repositories']) || !is_array($configJson['repositories'])) {
            $configJson['repositories'] = array();
        }

        $jsonModified = false;

        list($artifactRepositoryExists, $legacyRepositoryExists) = static::repositoriesExists($configJson);

        if (!$artifactRepositoryExists) {
            $configJson['repositories'] = array_merge(
                array(
                    array(
                        'type' => 'artifact',
                        'url'  => 'packages',
                    )
                ),
                $configJson['repositories']
            );

            $jsonModified = true;
            $messages[]   = 'artifact repository was added to root composer.json';
        }

        if (!$legacyRepositoryExists) {
            $configJson['repositories'] = array_merge(
                array(
                    array(
                        'type' => 'composer',
                        'url'  => 'https://legacy-packages-via.contao-community-alliance.org/',
                    )
                ),
                $configJson['repositories']
            );

            $jsonModified = true;
            $messages[]   = 'legacy packages repository was added to root composer.json';
        }

        if ($jsonModified) {
            $configJson['repositories'] = array_values($configJson['repositories']);
        }

        return $jsonModified;
    }

    /**
     * Determine if the artifact and legacy repository exist in the root composer.json.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @return array An array with two boolean items.
     */
    public static function repositoriesExists(&$configJson)
    {
        $artifactRepositoryExists = false;
        $legacyRepositoryExists   = false;

        foreach ($configJson['repositories'] as $repository) {
            if (!isset($repository['type'])) {
                continue;
            }

            if (
                $repository['type'] == 'artifact' &&
                preg_match('~(^packages|/packages)$~', rtrim($repository['url'], '/'))
            ) {
                $artifactRepositoryExists = true;
            }

            if (
                $repository['type'] == 'composer' &&
                (
                    $repository['url'] == 'http://legacy-packages-via.contao-community-alliance.org/' ||
                    $repository['url'] == 'https://legacy-packages-via.contao-community-alliance.org/'
                )
            ) {
                $legacyRepositoryExists = true;
            }
        }

        return array($artifactRepositoryExists, $legacyRepositoryExists);
    }

    /**
     * Remove the Contao Version and additional information from the root composer.json.
     *
     * Returns true when the config has been manipulated, false otherwise.
     *
     * @param array $configJson The json config (composer.json).
     *
     * @param array $messages   The destination buffer for messages raised by the update process.
     *
     * @return bool
     */
    public static function restoreNeededConfigKeys(&$configJson, &$messages)
    {
        $jsonModified = false;

        if (!isset($configJson['name'])) {
            $configJson['name'] = 'local/website';
            $messages[]         = 'name has been initialized to "local/website" in root composer.json!';
            $jsonModified       = true;
        }

        if (!isset($configJson['description'])) {
            $configJson['description'] = 'A local website project';
            $messages[]                = 'description has been initialized to "A local website project" ' .
                                         'in root composer.json!';
            $jsonModified              = true;
        }

        if (!isset($configJson['type'])) {
            $configJson['type'] = 'project';
            $messages[]         = 'type has been initialized to "project" in root composer.json!';
            $jsonModified       = true;
        }

        if (!isset($configJson['license'])) {
            $configJson['license'] = 'proprietary';
            $messages[]            = 'license has been initialized to "proprietary" in root composer.json!';
            $jsonModified          = true;
        }

        if (($configJson['type'] !== 'contao-module') && !isset($configJson['config']['component-dir'])) {
            if (!isset($configJson['config'])) {
                $configJson['config'] = array();
            }
            $configJson['config']['component-dir'] = '../assets/components';
            $messages[]                            = 'components installation path has been initialized to ' .
                                                     '"../assets/components" in root composer.json!';
            $jsonModified                          = true;
        }

        return $jsonModified;
    }
}
