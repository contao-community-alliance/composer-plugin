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

namespace ContaoCommunityAlliance\Composer\Plugin\Test\ConfigManipulator;

use Composer\Composer;
use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\ConfigManipulator;

class RemoveContaoVersionTest extends TestCase
{
	public function testNothingToDo()
	{
		$configJson = array();

		$messages = array();

		self::assertFalse(ConfigManipulator::removeObsoleteContaoVersion($configJson, $messages));
		self::assertEmpty($messages);

		self::assertEquals(
			array(),
			$configJson
		);
	}

	public function testRemoveVersion()
	{
		$configJson = array(
			'name'        => 'contao/core',
			'description' => 'contao core',
			'license'     => 'LGPL-3.0',
			'type'        => 'metapackage',
			'version'     => '0.0.0.0',
			'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::removeObsoleteContaoVersion($configJson, $messages));
		self::assertEquals(1, count($messages));

		self::assertEquals(
			array(),
			$configJson
		);
	}

	public function testNotRemoveVersion()
	{
		$configJson = array(
			'name'        => 'contao/core',
			'description' => 'contao core',
			'license'     => 'LGPL-3.0',
			'version'     => '0.0.0.0',
			'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::removeObsoleteContaoVersion($configJson, $messages));
		self::assertEmpty($messages);

		self::assertEquals(
			array(
				'name'        => 'contao/core',
				'description' => 'contao core',
				'license'     => 'LGPL-3.0',
				'version'     => '0.0.0.0',
				'provide'     => array('swiftmailer/swiftmailer' => '0.0.0.0')
			),
			$configJson
		);
	}
}
