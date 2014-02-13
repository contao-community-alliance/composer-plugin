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

use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;
use ContaoCommunityAlliance\Composer\Plugin\ConfigManipulator;

class RestoreNeededConfigKeysTest extends TestCase
{
	public function testSetName()
	{
		$configJson = array(
			'license'     => 'proprietary',
			'type'        => 'project',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'proprietary',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideName()
	{
		$configJson = array(
			'name'        => 'some_vendor/custom_project',
			'license'     => 'proprietary',
			'type'        => 'project',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'some_vendor/custom_project',
				'license'     => 'proprietary',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetType()
	{
		$configJson = array(
			'name'        => 'local/website',
			'license'     => 'proprietary',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'proprietary',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideType()
	{
		$configJson = array(
			'name'        => 'local/website',
			'license'     => 'proprietary',
			'type'        => 'custom-type',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'proprietary',
				'type'        => 'custom-type',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetLicense()
	{
		$configJson = array(
			'name'        => 'local/website',
			'type'        => 'project',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'proprietary',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideLicense()
	{
		$configJson = array(
			'name'        => 'local/website',
			'license'     => 'LGPL-3.0',
			'type'        => 'project',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'LGPL-3.0',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetDescription()
	{
		$configJson = array(
			'name'        => 'local/website',
			'type'        => 'project',
			'license'     => 'proprietary',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'proprietary',
				'type'        => 'project',
				'description' => 'A local website project',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideDescription()
	{
		$configJson = array(
			'name'        => 'local/website',
			'license'     => 'LGPL-3.0',
			'type'        => 'project',
			'description' => 'My Website',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'local/website',
				'license'     => 'LGPL-3.0',
				'type'        => 'project',
				'description' => 'My Website',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}
}
