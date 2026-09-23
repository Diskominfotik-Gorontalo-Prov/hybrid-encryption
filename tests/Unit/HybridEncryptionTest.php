<?php

/** Test unit untuk enkripsi berbasis key_id dan penyimpanan disk Laravel. */

namespace Aptika\HybridEncryption\Tests\Unit;

use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Aptika\HybridEncryption\Services\KeyPairService;
use Aptika\HybridEncryption\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class HybridEncryptionTest extends TestCase
{
    /** Memastikan array berhasil dienkripsi dan didekripsi dengan key_id. */
    public function test_encrypt_and_decrypt_by_key_id(): void
    {
        Storage::fake('local');
        config([
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
            'hybrid-encryption.key_storage.disk' => 'local',
            'hybrid-encryption.key_storage.visibility' => 'private',
            'hybrid-encryption.key_storage.public_prefix' => 'test/public',
            'hybrid-encryption.key_storage.private_prefix' => 'test/private',
        ]);

        $keyId = 'users/10/profile';
        $keys = app(KeyPairService::class);
        $paths = $keys->generate($keyId);
        $service = app(HybridEncryptionService::class);
        $original = ['user_id' => 10, 'secret' => 'rahasia'];
        $payload = $service->encrypt($keyId, $original);

        $this->assertSame($original, $service->decrypt($keyId, json_encode($payload, JSON_THROW_ON_ERROR)));
        Storage::disk('local')->assertExists($paths['public_path']);
        Storage::disk('local')->assertExists($paths['private_path']);
    }
}
