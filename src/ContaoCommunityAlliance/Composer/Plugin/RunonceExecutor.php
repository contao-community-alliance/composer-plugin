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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @copyright  2013-2015 Contao Community Alliance
 * @license    https://github.com/contao-community-alliance/composer-plugin/blob/master/LICENSE LGPL-3.0+
 * @link       http://c-c-a.org
 * @filesource
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

/**
 * Execute a list of runonce files within the Contao system.
 */
class RunonceExecutor extends \System
{
    /**
     * Create a new instance.
     * @codingStandardsIgnoreStart
     */
    public function __construct()
    {
        parent::__construct();
    }
    // @codingStandardsIgnoreEnd

    /**
     * Run a list of runonce files.
     *
     * @param array $runonces The list of runonce.php files.
     *
     * @return void
     */
    public function run(array $runonces)
    {
        // handle errors as exceptions
        set_error_handler(array($this, 'handleError'), (E_ALL & ~E_NOTICE));

        foreach ($runonces as $runonce) {
            try {
                if (is_file(TL_ROOT . DIRECTORY_SEPARATOR . $runonce)) {
                    require_once(TL_ROOT . DIRECTORY_SEPARATOR . $runonce);
                } else {
                    log_message('Skip non-existing runonce ' . $runonce);
                }
            } catch (\Exception $e) {
                log_message(
                    'Execute runonce ' . $runonce . ' failed with message:' .
                    PHP_EOL . $e->getMessage() .
                    PHP_EOL . $e->getTraceAsString()
                );
            }
        }

        // restore contao error handler
        restore_error_handler();
    }

    /**
     * Throw errors as error exceptions.
     *
     * @param int    $errno   Contains the level of the error raised, as an integer.
     *
     * @param string $errstr  The error message.
     *
     * @param string $errfile The file in which the error occured.
     *
     * @param int    $errline The line in the file on which the error occured.
     *
     * @return void
     *
     * @throws \ErrorException Always, the error converted to an ErrorException.
     */
    public function handleError($errno, $errstr, $errfile, $errline)
    {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
}
