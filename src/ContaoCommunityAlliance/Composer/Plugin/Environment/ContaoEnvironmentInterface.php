<?php

namespace ContaoCommunityAlliance\Composer\Plugin\Environment;

interface ContaoEnvironmentInterface
{
    public function getRoot();

    public function getVersion();

    public function getBuild();

    public function getFullVersion();

    public function getUploadPath();
}
