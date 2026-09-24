<?php

namespace Aptika\HybridEncryption\Commands;

use Aptika\HybridEncryption\Exceptions\KeyManagementException;
use Aptika\HybridEncryption\Services\KeyPairService;
use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Aptika\HybridEncryption\Services\AesKeyService;
use Illuminate\Console\Command;

/** Command untuk membuat RSA key pair pada satu disk dengan visibility terkonfigurasi. */
class GenerateKeyPairCommand extends Command
{
    protected $signature = 'hybrid-encryption:generate-key-pair {key_id : Identitas key, misalnya users/10/profile} {--force : Timpa key yang sudah ada} {--aes-source= : Sumber AES: app_key atau generated}';
    protected $description = 'Membuat RSA key pair dan menentukan sumber AES key berdasarkan key_id';

    /** Membuat key pair dan menampilkan lokasi penyimpanannya. */
    public function handle(KeyPairService $keys, HybridEncryptionService $crypto, AesKeyService $aes): int
    {
        $keyId = (string) $this->argument('key_id');

        try {
            if (!$this->option('force') && $keys->exists($keyId)) {
                $this->error("Pasangan key untuk key_id [{$keyId}] sudah ada.");
                $this->line('Key lama tidak diubah.');
                $this->line('Jika memang ingin mengganti key, jalankan ulang dengan --force.');

                return self::FAILURE;
            }
        } catch (KeyManagementException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $source = $this->option('aes-source') ?: $this->choice(
            'Gunakan APP_KEY Laravel sebagai AES key?',
            ['app_key', 'generated'],
            0
        );

        if (!in_array($source, ['app_key', 'generated'], true)) {
            $this->error('Sumber AES harus app_key atau generated.');
            return self::FAILURE;
        }

        $aesKey = null;
        if ($source === 'app_key' && !config('app.key')) {
            $this->warn('APP_KEY belum tersedia. Menjalankan php artisan key:generate.');
            if ($this->call('key:generate') !== self::SUCCESS) {
                return self::FAILURE;
            }
            config(['app.key' => env('APP_KEY')]);
        }

        if ($source === 'generated') {
            // AES key hanya ditampilkan satu kali dan tidak disimpan package.
            $aesKey = $aes->generate();
        }

        try {
            $paths = $keys->generate($keyId, (bool) $this->option('force'));
        } catch (KeyManagementException $exception) {
            $this->error($exception->getMessage());
            $this->line('Tidak ada key yang diubah. Periksa konfigurasi disk dan key_id.');

            return self::FAILURE;
        }
        $this->info("Key berhasil dibuat untuk key_id [{$paths['key_id']}].");
        $this->line("AES source: {$source}");
        $this->line("Disk: {$paths['disk']}");
        $this->line("Visibility: {$paths['visibility']}");
        $this->line("Public: {$paths['public_path']}");
        $this->line("Private: {$paths['private_path']}");
        $this->line("AES fingerprint: {$crypto->aesKeyFingerprint($aesKey)}");
        if ($aesKey !== null) {
            $this->warn('Simpan AES key ini di secret manager/environment. Package tidak menyimpannya.');
            $this->line("AES key: {$aesKey}");
        } else {
            $this->line('AES key: menggunakan APP_KEY Laravel (nilai tidak ditampilkan).');
        }
        $this->warn('Jangan membagikan private key atau commit file tersebut ke Git.');

        return self::SUCCESS;
    }
}
