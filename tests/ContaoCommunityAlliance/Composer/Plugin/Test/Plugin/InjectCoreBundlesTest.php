<?php

/**
 * Contao Composer Installer
 *
 * Copyright (C) 2013 Contao Community Alliance
 *
 * @package contao-composer
 * @author  Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @link    http://c-c-a.org
 * @license LGPL-3.0+
 */

namespace ContaoCommunityAlliance\Composer\Plugin\Test\Plugin;

use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use ContaoCommunityAlliance\Composer\Plugin\Plugin;
use ContaoCommunityAlliance\Composer\Plugin\Test\TestCase;

/**
 * Test class for various micro issues that do not require an own test class.
 *
 * @package ContaoCommunityAlliance\Composer\Plugin\Test\Plugin
 */
class InjectCoreBundlesTest extends TestCase
{
    /** @var Filesystem */
    protected $fs;

    /** @var array */
    protected $config;

    /** @var string */
    protected $vendorDir;

    /** @var string */
    protected $binDir;

    /** @var string */
    protected $rootDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $baseDir = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR;

        $this->fs = new Filesystem;

        $this->vendorDir = $baseDir . 'composer-test-vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $baseDir . 'composer-test-bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->config = array(
            'config' => array(
                'vendor-dir'   => $this->vendorDir,
                'bin-dir'      => $this->binDir,
                'repositories' => array('packagist' => false)
            ),
        );

        $this->rootDir = $baseDir . 'composer-test-contao';
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown()
    {
        $this->fs->removeDirectory($this->vendorDir);
        $this->fs->removeDirectory($this->binDir);
        $this->fs->removeDirectory($this->rootDir);
    }

    /**
     * Test that the core bundles get correctly injected.
     *
     * @return void
     */
    public function testInjectCoreBundles()
    {
        $inOut    = $this->getMock('Composer\IO\IOInterface');
        $factory  = new Factory();
        $composer = $factory->createComposer($inOut, $this->config);
        $plugin   = new Plugin();
        $local    = $composer->getRepositoryManager()->getLocalRepository();

        if ($core = $local->findPackages('contao/core')) {
            $this->fail('Contao core has already been injected, found version ' . $core[0]->getVersion());
        }

        $plugin->activate($composer, $inOut);

        if (!($core = $local->findPackages('contao/core'))) {
            $this->fail('Contao core has not been injected.');
        }
        $core = $core[0];

        $constraint = new Constraint('=', $core->getVersion());

        $pool = new Pool('dev');
        $pool->addRepository($local);
        $this->assertNotNull($core = $pool->whatProvides('contao/core', $constraint));

        // bundle names + 'contao-community-alliance/composer-client'
        $this->assertCount(8, $core[0]->getRequires());

        foreach (array(
            'contao/calendar-bundle',
            'contao/comments-bundle',
            'contao/core-bundle',
            'contao/faq-bundle',
            'contao/listing-bundle',
            'contao/news-bundle',
            'contao/newsletter-bundle',
        ) as $bundleName) {
            $this->assertNotNull($matches = $pool->whatProvides($bundleName, $constraint));
            $this->assertCount(1, $matches);
            $this->assertEquals('metapackage', $matches[0]->getType());
        }
    }
}
