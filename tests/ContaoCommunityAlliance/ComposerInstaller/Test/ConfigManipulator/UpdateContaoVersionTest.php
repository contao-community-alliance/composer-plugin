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

class UpdateContaoVersionTest extends TestCase
{
	public function testNothingToDo()
	{
		$configJson = array(
			'version' => VERSION . '.' . BUILD . '.0'
		);

		$messages = array();

		$composer = new Composer();
		$package = $this->getMock('Composer\Package\RootPackageInterface');
		$package
			->expects($this->any())
			->method('getVersion')
			->will($this->returnCallback(function(){ return VERSION . '.' . BUILD; }));

		$composer->setPackage($package);

		self::assertFalse(ConfigManipulator::updateContaoVersion(
			$configJson,
			$messages,
			$composer
		));
		self::assertEmpty($messages);

		self::assertEquals(
			array(
				'version' => VERSION . '.' . BUILD . '.0'
			),
			$configJson
		);
	}

	public function testUpgradeVersion()
	{
		$configJson = array(
			'version' => '0.0.0.0'
		);

		$messages = array();

		$composer = new Composer();
		$package = $this->getMock('Composer\Package\RootPackageInterface');
		$package
			->expects($this->any())
			->method('getVersion')
			->will($this->returnCallback(function(){ return '0.0.0.0'; }));

		$composer->setPackage($package);

		self::assertTrue(ConfigManipulator::updateContaoVersion(
			$configJson,
			$messages,
			$composer
		));
		self::assertEquals(1, count($messages));

		self::assertEquals(
			array(
				'version' => VERSION . '.' . BUILD
			),
			$configJson
		);
	}

	public function testDowngradeVersion()
	{
		$configJson = array(
			'version' => '42.11.5.1772'
		);

		$messages = array();

		$composer = new Composer();
		$package = $this->getMock('Composer\Package\RootPackageInterface');
		$package
			->expects($this->any())
			->method('getVersion')
			->will($this->returnCallback(function(){ return '42.11.5.1772'; }));

		$composer->setPackage($package);

		self::assertTrue
			(
			ConfigManipulator::updateContaoVersion(
			$configJson,
			$messages,
			$composer
		));
		self::assertEquals(1, count($messages));

		self::assertEquals(
			array(
				'version' => VERSION . '.' . BUILD
			),
			$configJson
		);
	}
}
