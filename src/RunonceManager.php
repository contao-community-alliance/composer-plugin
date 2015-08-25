<?php

namespace ContaoCommunityAlliance\Composer\Plugin;

class RunonceManager
{
    /**
     * @var array
     */
    private $files;

    /**
     * @var string
     */
    private $targetFile;

    /**
     * Constructor.
     *
     * @param string      $targetFile
     */
    public function __construct($targetFile)
    {
        $this->targetFile = $targetFile;
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

        if (file_exists($this->targetFile) && (!is_writable($this->targetFile) || !is_file($this->targetFile))) {
            throw new \RuntimeException(sprintf('Runonce file "%s" exists but is not readable.', $this->targetFile));
        }

        $this->readCurrentFile();

        $files = array_unique(array_merge($this->readCurrentFile(), $this->files));
        $data  = json_encode($files);
        $dump  = var_export($files, true);

        $buffer = <<<PHP
<?php

/** files: $data */

\$runonce = function() {
    foreach ($dump as \$file) {
        try {
            include \$file;
        } catch (\Exception \$e) {}

        \$relpath = str_replace(TL_ROOT . DIRECTORY_SEPARATOR, '', \$file);

        if (!unlink(\$file)) {
            throw new \Exception("The file \$relpath cannot be deleted. Please remove the file manually and correct the file permission settings on your server.");
        }

        \System::log("File \$relpath ran once and has then been removed successfully", __METHOD__, TL_GENERAL);
    }
};

\$runonce();

PHP;

        if (false === file_put_contents($this->targetFile, $buffer)) {
            throw new \RuntimeException(sprintf('Could not write runonce file to "%s"', $this->targetFile));
        }

        $this->files = [];
    }

    /**
     * Returns files in the current runonce script.
     *
     * @return array
     *
     * @todo we should probably better rename the file and call it again.
     */
    private function readCurrentFile()
    {
        if (!is_file($this->targetFile)) {
            return [];
        }

        $content = file_get_contents($this->targetFile);
        $matches = [];

        if (preg_match('/\/\*\* files: ({.*}) \*\/\n/', $content, $matches)) {
            $json = $matches[1];
            $files = json_decode($json, true);

            if (null !== $files) {
                return $files;
            }
        }

        return [];
    }
}
