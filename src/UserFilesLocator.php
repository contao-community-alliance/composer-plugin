<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

class UserFilesLocator
{
    /**
     * @var string
     */
    private $rootDir;

    /**
     * Constructor.
     *
     * @param string $rootDir
     */
    public function __construct($rootDir)
    {
        $this->rootDir = $rootDir;
    }

    /**
     * Locates the user files dir.
     *
     * @return string
     */
    public function locate()
    {
        return $this->rootDir . '/files';
    }
}
