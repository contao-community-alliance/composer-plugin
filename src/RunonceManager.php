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

use Composer\Util\Filesystem;

/**
 * RunonceManager collects runonce files and dumps them into app/Resources
 * to be executed after installation or update.
 *
 * @author Andreas Schempp <https://github.com/aschempp>
 */
class RunonceManager
{
    /**
     * @var string
     */
    private $targetFile;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array
     */
    private $files;

    /**
     * Constructor.
     *
     * @param string     $targetFile
     * @param Filesystem $filesystem
     */
    public function __construct($targetFile, Filesystem $filesystem = null)
    {
        $this->targetFile = $targetFile;
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * Adds an absolute path to the runonce file cache. The runonce file is NOT written immediately.
     *
     * @param string $path
     */
    public function addFile($path)
    {
        if (!is_readable($path) || !is_file($path)) {
            return;
        }

        $this->files[] = $path;
    }

    /**
     * Dumps runonce file to the target file given in the constructor.
     *
     * @throws \RuntimeException if runonce file exists but is not writable.
     */
    public function dump()
    {
        if (empty($this->files)) {
            return;
        }

        $buffer = <<<'PHP'
<?php

$runonce = function(array $files, $delete = false) {
    foreach ($files as $file) {
        try {
            include $file;
        } catch (\Exception $e) {}

        $relpath = str_replace(TL_ROOT . DIRECTORY_SEPARATOR, '', $file);

        if ($delete && !unlink($file)) {
            throw new \Exception("The file $relpath cannot be deleted. Please remove the file manually and correct the file permission settings on your server.");
        }
    }
};

PHP;

        if (file_exists($this->targetFile)) {
            if (!is_writable($this->targetFile) || !is_file($this->targetFile)) {
                throw new \RuntimeException(sprintf('Runonce file "%s" exists but is not writable.', $this->targetFile));
            }

            $current = str_replace('.php', '_'.substr(md5(mt_rand()), 0, 8).'.php', $this->targetFile);
            $this->filesystem->rename($this->targetFile, $current);

            $buffer .= "\n\$runonce(array('" . $current . "'), true)\n";
        }

        $buffer .= "\n\$runonce(" . var_export(array_unique($this->files), true) . ")\n";

        if (false === file_put_contents($this->targetFile, $buffer)) {
            throw new \RuntimeException(sprintf('Could not write runonce file to "%s"', $this->targetFile));
        }

        $this->files = [];
    }
}
