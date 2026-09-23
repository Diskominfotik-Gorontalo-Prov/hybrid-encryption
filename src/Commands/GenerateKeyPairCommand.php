<?php

namespace Aptika\HybridEncryption\Commands;

use Aptika\HybridEncryption\Services\KeyPairService;
use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Illuminate\Console\Command;

/** Command untuk membuat RSA key pair pada satu disk dengan visibility terkonfigurasi. */
class GenerateKeyPairCommand extends Command
{
    protected $signature = 'hybrid-encryption:generate-key-pair {key_id : Identitas key, misalnya users/10/profile} {--force : Timpa key yang sudah ada}';
    protected $description = 'Membuat RSA public/private key berdasarkan key_id';

    /** Membuat key pair dan menampilkan lokasi penyimpanannya. */
    public function handle(KeyPairService $keys, HybridEncryptionService $crypto): int
    {
        $paths = $keys->generate((string) $this->argument('key_id'), (bool) $this->option('force'));
        $this->info("Key berhasil dibuat untuk key_id [{$paths['key_id']}].");
        $this->line("Disk: {$paths['disk']}");
        $this->line("Visibility: {$paths['visibility']}");
        $this->line("Public: {$paths['public_path']}");
        $this->line("Private: {$paths['private_path']}");
        $this->line("AES fingerprint: {$crypto->aesKeyFingerprint()}");
        $this->warn('Jangan membagikan private key atau commit file tersebut ke Git.');

        return self::SUCCESS;
    }
}
