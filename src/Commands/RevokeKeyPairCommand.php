<?php

namespace Aptika\HybridEncryption\Commands;

use Aptika\HybridEncryption\Exceptions\KeyManagementException;
use Aptika\HybridEncryption\Services\KeyPairService;
use Illuminate\Console\Command;

/** Command untuk mencabut dan menghapus pasangan RSA key. */
class RevokeKeyPairCommand extends Command
{
    protected $signature = 'aptika-hybrid-encryption:revoke-key-pair {key_id=default : Identitas key yang akan dicabut} {--force : Lewati konfirmasi}';
    protected $description = 'Mencabut dan menghapus public/private key berdasarkan key_id';

    /** Menghapus key setelah konfirmasi pengguna. */
    public function handle(KeyPairService $keys): int
    {
        $keyId = trim((string) $this->argument('key_id')) ?: 'default';

        try {
            if (!$keys->exists($keyId)) {
                $this->warn("Pasangan key untuk key_id [{$keyId}] tidak ditemukan. Tidak ada yang dihapus.");

                return self::SUCCESS;
            }
        } catch (KeyManagementException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $answer = $this->ask(
                "Key [{$keyId}] akan dicabut dan payload lama tidak dapat didekripsi. Berapa hasil 2 + 3?"
            );
            if ((int) $answer !== 5) {
                $this->info('Pembatalan: key tetap disimpan.');

                return self::SUCCESS;
            }
        }

        try {
            $removed = $keys->remove($keyId);
        } catch (KeyManagementException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Key untuk key_id [{$keyId}] berhasil dicabut.");
        $this->line("Public dihapus: " . ($removed['removed_public'] ? 'ya' : 'tidak ada'));
        $this->line("Private dihapus: " . ($removed['removed_private'] ? 'ya' : 'tidak ada'));
        $this->warn('Payload yang hanya dapat dibuka dengan key ini tidak dapat didekripsi lagi.');

        return self::SUCCESS;
    }
}
