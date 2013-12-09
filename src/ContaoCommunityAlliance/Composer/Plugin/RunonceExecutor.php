<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Dominik Zogg <dominik.zogg@gmail.com>
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author  Tristan Lins <tristan.lins@bit3.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin;

/**
 * Execute a list of runonce files within the Contao system.
 */
class RunonceExecutor extends \System
{
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Run a list of runonce files.
	 *
	 * @param array $runonces
	 */
	public function run(array $runonces)
	{
		foreach ($runonces as $runonce) {
			try {
				require_once($runonce);
			}
			catch (\Exception $e) {
				// first trigger an error to write this into the log file
				trigger_error(
					$e->getMessage() . "\n" . $e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				$this->log(
					$e->getMessage() . "\n" . $e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}
