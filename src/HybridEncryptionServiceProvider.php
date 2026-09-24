<?php

/**
 * Mengintegrasikan service enkripsi, konfigurasi, binding facade, dan command
 * dengan container service serta siklus console Laravel.
 */
namespace Aptika\HybridEncryption;

use Aptika\HybridEncryption\Commands\GenerateKeyPairCommand;
use Aptika\HybridEncryption\Commands\RevokeKeyPairCommand;
use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Aptika\HybridEncryption\Services\KeyPairService;
use Illuminate\Support\ServiceProvider;

class HybridEncryptionServiceProvider extends ServiceProvider
{
    /** Mendaftarkan konfigurasi dan singleton service enkripsi. */
    public function register(): void
    {
        // Gunakan konfigurasi default package yang dapat ditimpa aplikasi.
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-encryption.php', 'hybrid-encryption');

        // Singleton memastikan facade dan dependency injection memakai satu instance service.
        $this->app->singleton('hybrid-encryption', fn () => new HybridEncryptionService());

        // Service untuk membuat dan membaca key pair berdasarkan key_id.
        $this->app->singleton(KeyPairService::class);

        // Agar class konkret juga dapat di-resolve melalui container Laravel.
        $this->app->alias('hybrid-encryption', HybridEncryptionService::class);
    }
    /** Mendaftarkan target publish konfigurasi dan command Artisan. */
    public function boot(): void
    {
        // Izinkan aplikasi utama mem-publish konfigurasi package.
        $this->publishes([__DIR__.'/../config/hybrid-encryption.php' => config_path('hybrid-encryption.php')], 'hybrid-encryption-config');

        // Command hanya didaftarkan saat Laravel berjalan dalam mode console.
        if ($this->app->runningInConsole()) $this->commands([
            GenerateKeyPairCommand::class,
            RevokeKeyPairCommand::class,
        ]);
    }
}
