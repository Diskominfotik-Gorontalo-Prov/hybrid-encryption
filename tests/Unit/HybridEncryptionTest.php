<?php

/** Test unit untuk mode AES APP_KEY, AES generated, dan payload string. */

namespace Aptika\HybridEncryption\Tests\Unit;

use Aptika\HybridEncryption\Exceptions\DecryptionException;
use Aptika\HybridEncryption\Exceptions\EncryptionException;
use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Aptika\HybridEncryption\Services\KeyPairService;
use Aptika\HybridEncryption\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class HybridEncryptionTest extends TestCase
{
    private string $keyId = 'users/10/profile';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
            'hybrid-encryption.key_storage.disk' => 'local',
            'hybrid-encryption.key_storage.visibility' => 'private',
            'hybrid-encryption.key_storage.public_prefix' => 'test/public',
            'hybrid-encryption.key_storage.private_prefix' => 'test/private',
        ]);

        app(KeyPairService::class)->generate($this->keyId);
    }

    /** Memastikan array memakai AES hasil derivasi APP_KEY. */
    public function test_encrypt_and_decrypt_with_app_key(): void
    {
        $service = app(HybridEncryptionService::class);
        $original = ['user_id' => 10, 'secret' => 'rahasia'];
        $payload = $service->encrypt($this->keyId, $original);

        $this->assertSame('app_key', $payload['aes_source']);
        $appKeyMaterial = base64_decode(substr((string) config('app.key'), 7), true);
        $this->assertSame(hash('sha256', $appKeyMaterial), $service->aesKeyFingerprint());
        $this->assertSame($original, $service->decrypt($this->keyId, $payload));
    }

    /** Memastikan string JSON dari payload dapat langsung didekripsi. */
    public function test_payload_json_string_can_be_decrypted(): void
    {
        $service = app(HybridEncryptionService::class);
        $payload = $service->encrypt($this->keyId, 'alfandy');

        $this->assertSame('alfandy', $service->decrypt(
            $this->keyId,
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
    }

    /** Memastikan AES generated dapat diberikan saat encrypt dan decrypt. */
    public function test_encrypt_and_decrypt_with_provided_aes_key(): void
    {
        $service = app(HybridEncryptionService::class);
        $aesKey = 'base64:' . base64_encode(random_bytes(32));
        $original = ['feature' => 'bantuan', 'enabled' => true];
        $payload = $service->encrypt($this->keyId, $original, $aesKey);

        $this->assertSame('provided', $payload['aes_source']);
        $this->assertSame($original, $service->decrypt($this->keyId, $payload, $aesKey));
    }

    /** Memastikan AES key yang salah ditolak. */
    public function test_wrong_provided_aes_key_is_rejected(): void
    {
        $service = app(HybridEncryptionService::class);
        $aesKey = 'base64:' . base64_encode(random_bytes(32));
        $wrongAesKey = 'base64:' . base64_encode(random_bytes(32));
        $payload = $service->encrypt($this->keyId, 'rahasia', $aesKey);

        $this->expectException(DecryptionException::class);
        $service->decrypt($this->keyId, $payload, $wrongAesKey);
    }

    /** Memastikan AES key dengan ukuran yang salah ditolak saat encrypt. */
    public function test_invalid_provided_aes_key_is_rejected(): void
    {
        $this->expectException(EncryptionException::class);

        app(HybridEncryptionService::class)->encrypt($this->keyId, 'rahasia', 'terlalu-pendek');
    }

    /** Memastikan command dapat membuat RSA key dan menghasilkan AES generated. */
    public function test_generate_key_pair_command_outputs_generated_aes_key(): void
    {
        $this->artisan('hybrid-encryption:generate-key-pair', [
            'key_id' => 'features/command',
            '--aes-source' => 'generated',
        ])
            ->expectsOutput('AES source: generated')
            ->expectsOutput('Disk: local')
            ->expectsOutputToContain('AES key: base64:')
            ->assertExitCode(0);
    }
}
