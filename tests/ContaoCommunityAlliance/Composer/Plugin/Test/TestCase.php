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

namespace ContaoCommunityAlliance\Composer\Plugin\Test;

use Composer\Util\Filesystem;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{

	protected function ensureDirectoryExistsAndClear($directory)
	{
		$fs = new Filesystem();
		if (is_dir($directory)) {
			$fs->removeDirectory($directory);
		}
		mkdir($directory, 0777, true);
	}
}
