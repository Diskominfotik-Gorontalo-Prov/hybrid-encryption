<?php

namespace Aptika\HybridEncryption\Commands;

use Aptika\HybridEncryption\Services\AesKeyService;
use Illuminate\Console\Command;

/** Command untuk membuat AES-256 key yang tidak disimpan oleh package. */
class GenerateAesKeyCommand extends Command
{
    protected $signature = 'hybrid-encryption:generate-aes-key';
    protected $description = 'Membuat AES-256 key acak yang siap digunakan aplikasi';

    /** Membuat dan menampilkan AES key satu kali. */
    public function handle(AesKeyService $aes): int
    {
        $key = $aes->generate();

        $this->info('AES-256 key berhasil dibuat.');
        $this->line("AES key: {$key}");
        $this->line("Fingerprint: {$aes->fingerprint($key)}");
        $this->warn('Simpan AES key di secret manager/environment. Package tidak menyimpannya.');
        $this->warn('Jangan memasukkan AES key ke Git, log, response API, atau frontend.');

        return self::SUCCESS;
    }
}
