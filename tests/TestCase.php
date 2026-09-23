<?php

/** Test case dasar Laravel yang memuat package melalui Orchestra Testbench. */
namespace Aptika\HybridEncryption\Tests;
use Aptika\HybridEncryption\HybridEncryptionServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
abstract class TestCase extends Orchestra
{
    /** Mendaftarkan provider package pada aplikasi test yang terisolasi. */
    protected function getPackageProviders($app): array
    {
        return [HybridEncryptionServiceProvider::class];
    }
}
