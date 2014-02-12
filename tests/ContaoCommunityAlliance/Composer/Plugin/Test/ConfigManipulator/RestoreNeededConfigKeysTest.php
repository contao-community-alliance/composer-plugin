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
			'license' => 'proprietary',
			'type'    => 'project',
			'version' => '',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideName()
	{
		$configJson = array(
			'name'    => 'some_vendor/custom_project',
			'license' => 'proprietary',
			'type'    => 'project',
			'version' => '',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'        => 'some_vendor/custom_project',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetType()
	{
		$configJson = array(
			'name'    => 'local/website',
			'license' => 'proprietary',
			'version' => '',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideType()
	{
		$configJson = array(
			'name'    => 'local/website',
			'license' => 'proprietary',
			'type'    => 'custom-type',
			'version' => '',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'custom-type',
				'version' => '',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetLicense()
	{
		$configJson = array(
			'name'    => 'local/website',
			'type'    => 'project',
			'version' => '',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideLicense()
	{
		$configJson = array(
			'name'    => 'local/website',
			'license' => 'LGPL-3.0',
			'type'    => 'project',
			'version' => '',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'LGPL-3.0',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}

	public function testSetVersion()
	{
		$configJson = array(
			'name'    => 'local/website',
			'license' => 'proprietary',
			'type'    => 'project',
		);

		$messages = array();

		self::assertTrue(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '',
			),
			$configJson
		);
		self::assertEquals(1, count($messages));
	}

	public function testDoNotOverrideVersion()
	{
		$configJson = array(
			'name'    => 'local/website',
			'license' => 'proprietary',
			'type'    => 'project',
			'version' => '1.0.0.0',
		);

		$messages = array();

		self::assertFalse(ConfigManipulator::restoreNeededConfigKeys($configJson, $messages));

		self::assertEquals(
			array(
				'name'    => 'local/website',
				'license' => 'proprietary',
				'type'    => 'project',
				'version' => '1.0.0.0',
			),
			$configJson
		);
		self::assertEmpty($messages);
	}
}
