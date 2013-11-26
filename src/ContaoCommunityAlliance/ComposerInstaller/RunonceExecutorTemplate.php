<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use System;

if (!class_exists('ContaoCommunityAlliance\ComposerInstaller\RunonceExecutor')) {
	class RunonceExecutor extends System
	{
		public function __construct()
		{
			parent::__construct();
		}

		public function run($runonces)
		{
			foreach ($runonces as $runonce) {
				try {
					require_once(TL_ROOT . DIRECTORY_SEPARATOR . $runonce);
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
}

$executor = new RunonceExecutor();
$executor->run(TEMPLATE_RUNONCE_ARRAY);
