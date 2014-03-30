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
				log_message('Execute runonce ' . $runonce . ' failed with message:' . PHP_EOL . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
			}
		}
	}
}
