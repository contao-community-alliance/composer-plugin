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

namespace ContaoCommunityAlliance\Composer\Plugin;

use Composer\Util\Filesystem;

/**
 * RunonceManager collects runonce files and dumps them into app/Resources to be executed after installation or update.
 */
class RunonceManager
{
    /**
     * Name of the target runonce file to write the aggregated runonce handler to.
     *
     * @var string
     */
    private $targetFile;

    /**
     * The file system instance.
     *
     * @var Filesystem
     */
    private $filesystem;

    /**
     * The run once files to add.
     *
     * @var array
     */
    private $files;

    /**
     * Constructor.
     *
     * @param string     $targetFile Name of the target runonce file to write the aggregated runonce handler to.
     *
     * @param Filesystem $filesystem The file system instance.
     */
    public function __construct($targetFile, Filesystem $filesystem = null)
    {
        $this->targetFile = $targetFile;
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * Adds an absolute path to the runonce file cache. The runonce file is NOT written immediately.
     *
     * @param string $path Path to the runonce file.
     *
     * @return void
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
     * @throws \RuntimeException If the combined runonce file exists but is not writable.
     *
     * @return void
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
            throw new \Exception(
                'The file ' . $relpath . ' cannot be deleted. ' .
                'Please remove the file manually and correct the file permission settings on your server.'
            );
        }
    }
};

PHP;

        if (file_exists($this->targetFile)) {
            if (!is_writable($this->targetFile) || !is_file($this->targetFile)) {
                throw new \RuntimeException(
                    sprintf('Runonce file "%s" exists but is not writable.', $this->targetFile)
                );
            }

            $current = str_replace('.php', '_'.substr(md5(mt_rand()), 0, 8).'.php', $this->targetFile);
            $this->filesystem->rename($this->targetFile, $current);

            $buffer .= "\n\$runonce(array('" . $current . "'), true);\n";
        }

        $buffer .= "\n\$runonce(" . var_export(array_unique($this->files), true) . ");\n";

        $this->filesystem->ensureDirectoryExists(dirname($this->targetFile));

        if (false === file_put_contents($this->targetFile, $buffer)) {
            throw new \RuntimeException(sprintf('Could not write runonce file to "%s"', $this->targetFile));
        }

        $this->files = [];
    }
}
