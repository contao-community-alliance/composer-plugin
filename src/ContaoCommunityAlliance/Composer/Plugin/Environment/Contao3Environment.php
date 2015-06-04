<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Environment;

use Composer\Package\Version\VersionParser;
use ContaoCommunityAlliance\Composer\Plugin\Exception\ConstantsNotFoundException;

class Contao3Environment implements ContaoEnvironmentInterface
{
    private $rootDir;
    private $version;
    private $build;
    private $uploadPath;

    public function __construct($rootDir)
    {
        $this->rootDir = $rootDir;
    }

    public function getRoot()
    {
        return $this->rootDir;
    }

    public function getVersion()
    {
        if (null === $this->version) {
            $constantsFile = $this->rootDir . '/system/config/constants.php';

            if (!file_exists($constantsFile)) {
                throw new ConstantsNotFoundException('Could not find constants.php in ' . $this->rootDir);
            }

            $contents = file_get_contents($constantsFile);

            if (!preg_match('#define\(\'VERSION\', \'([^\']+)\'\);#', $contents, $match)) {
                throw new ConstantsNotFoundException('Could not find the Contao version.');
            }

            $this->version = $match[1];
        }

        return $this->version;
    }

    public function getBuild()
    {
        if (null === $this->build) {
            $constantsFile = $this->rootDir . '/system/config/constants.php';

            if (!file_exists($constantsFile)) {
                throw new ConstantsNotFoundException('Could not find constants.php in ' . $this->rootDir);
            }

            $contents = file_get_contents($constantsFile);

            if (!preg_match('#define\(\'BUILD\', \'([^\']+)\'\);#', $contents, $match)) {
                throw new ConstantsNotFoundException('Could not find the Contao build.');
            }

            $this->build = $match[1];
        }

        return $this->build;
    }

    public function getFullVersion()
    {
        return $this->getVersion() . '.' . $this->getBuild();
    }

    public function getUploadPath()
    {
        if (null === $this->uploadPath) {
            $this->uploadPath = $this->extractKeyFromConfigPath($this->rootDir . '/system/config/', 'uploadPath');
        }

        return $this->uploadPath;
    }

    /**
     * Retrieve a config value from the given config path.
     *
     * @param string $configPath The path where the config files are located.
     * @param string $key        The config key to retrieve.
     *
     * @return mixed
     */
    private function extractKeyFromConfigPath($configPath, $key)
    {
       $value = $this->extractKeyFromConfigFile(
            $configPath . 'default.php',
            $key
        );

        if ($override = $this->extractKeyFromConfigFile(
            $configPath . 'localconfig.php',
            $key
        )) {
            $value = $override;
        }

        return $value;
    }

    /**
     * Retrieve a config value from the given config file.
     *
     * This is a very rudimentary parser for the Contao config files.
     * It does only support on line assignments and primitive types but this is enough for this
     * plugin to retrieve the data it needs to retrieve.
     *
     * @param string $configFile The filename.
     * @param string $key        The config key to retrieve.
     *
     * @return mixed
     */
    private function extractKeyFromConfigFile($configFile, $key)
    {
        if (!file_exists($configFile)) {
            return null;
        }

        $value  = null;
        $lines  = file($configFile);
        $search = '$GLOBALS[\'TL_CONFIG\'][\'' . $key . '\']';
        $length = strlen($search);
        foreach ($lines as $line) {
            $tline = trim($line);
            if (strncmp($search, $tline, $length) === 0) {
                $parts = explode('=', $tline, 2);
                $tline = trim($parts[1]);

                if ($tline === 'true;') {
                    $value = true;
                } elseif ($tline === 'false;') {
                    $value = false;
                } elseif ($tline === 'null;') {
                    $value = null;
                } elseif ($tline === 'array();') {
                    $value = array();
                } elseif ($tline[0] === '\'') {
                    $value = substr($tline, 1, -2);
                } else {
                    $value = substr($tline, 0, -1);
                }
            }
        }

        return $value;
    }

    public function getSwiftMailerVersion()
    {
        switch ($this->getVersion()) {
            // FIXME: drop support for Contao 2.11
            case '2.11':
                $file = $this->getRoot() . '/plugins/swiftmailer/VERSION';
                break;

            case '3.0':
                $file = $this->getRoot() . '/system/vendor/swiftmailer/VERSION';
                break;

            case '3.1':
            case '3.2':
                $file = $this->getRoot() . '/system/modules/core/vendor/swiftmailer/VERSION';
                break;

            default:
                throw new UnknownSwiftmailerException('Dunno how to find SwiftMailer for Contao ' . $this->getVersion());
        }

        if (!is_file($file)) {
            throw new UnknownSwiftmailerException('SwiftMailer version not found at ' . $file);
        }

        $versionParser      = new VersionParser();
        $prettySwiftVersion = file_get_contents($file);
        $prettySwiftVersion = substr($prettySwiftVersion, 6);
        $prettySwiftVersion = trim($prettySwiftVersion);

        return $versionParser->normalize($prettySwiftVersion);
    }
}
