<?php

namespace Aptika\HybridEncryption\Services;

use Aptika\HybridEncryption\Exceptions\DecryptionException;
use Aptika\HybridEncryption\Exceptions\EncryptionException;
use Aptika\HybridEncryption\Exceptions\KeyManagementException;
use JsonException;

/** Service enkripsi yang selalu menggunakan key pair berdasarkan key_id. */
class HybridEncryptionService
{
    /** Mengenkripsi data menggunakan public key untuk key_id tertentu. */
    public function encrypt(string $keyId, array|string $data): array
    {
        try {
            $pem = app(KeyPairService::class)->publicKey($keyId);
        } catch (KeyManagementException $exception) {
            throw new EncryptionException($exception->getMessage(), previous: $exception);
        }

        $publicKey = openssl_pkey_get_public($pem);
        if (!$publicKey) {
            throw new EncryptionException('Public key tidak valid.');
        }

        // Array diubah menjadi JSON sebelum dienkripsi.
        $plaintext = is_array($data)
            ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $data;

        // AES key diturunkan dari APP_KEY agar pengirim dan penerima memakai key yang sama.
        $aesKey = $this->aesKey();
        // IV tetap dibuat baru untuk setiap payload agar aman digunakan berulang.
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, config('hybrid-encryption.cipher', 'aes-256-gcm'), $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new EncryptionException('AES encryption gagal.');
        }

        // RSA hanya membungkus AES key, bukan seluruh data.
        if (!openssl_public_encrypt($aesKey, $encryptedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new EncryptionException('RSA encryption untuk AES key gagal.');
        }

        return [
            'version' => 2,
            'alg' => 'RSA-OAEP+A256GCM+APP_KEY',
            'encrypted_key' => base64_encode($encryptedKey),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];
    }

    /** Mendekripsi data menggunakan private key untuk key_id tertentu. */
    public function decrypt(string $keyId, array|string $payload, ?string $passphrase = null): array|string
    {
        // Payload boleh diterima sebagai array PHP atau string JSON dari request API.
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new DecryptionException('Payload JSON tidak valid.');
            }

            if (!is_array($payload)) {
                throw new DecryptionException('Payload JSON harus berupa object.');
            }
        }

        $this->validatePayload($payload);

        try {
            $pem = app(KeyPairService::class)->privateKey($keyId);
        } catch (KeyManagementException $exception) {
            throw new DecryptionException($exception->getMessage(), previous: $exception);
        }

        $privateKey = openssl_pkey_get_private($pem, $passphrase ?? '');
        if (!$privateKey) {
            throw new DecryptionException('Private key tidak valid.');
        }

        $encryptedKey = $this->b64($payload['encrypted_key']);
        $iv = $this->b64($payload['iv']);
        $tag = $this->b64($payload['tag']);
        $ciphertext = $this->b64($payload['data']);

        if (!openssl_private_decrypt($encryptedKey, $aesKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new DecryptionException('RSA decrypt AES key gagal.');
        }

        // Menolak payload dari APP_KEY yang berbeda sebelum proses AES.
        if (!hash_equals($this->aesKey(), $aesKey)) {
            throw new DecryptionException('APP_KEY aplikasi tidak cocok dengan APP_KEY pembuat payload.');
        }

        // AES-GCM memeriksa tag dan menolak payload yang sudah diubah.
        $plaintext = openssl_decrypt($ciphertext, config('hybrid-encryption.cipher', 'aes-256-gcm'), $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new DecryptionException('AES decrypt gagal atau payload telah dimodifikasi.');
        }

        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $plaintext;
        } catch (JsonException) {
            return $plaintext;
        }
    }

    /** Mengembalikan sidik jari AES tanpa membocorkan nilai AES key. */
    public function aesKeyFingerprint(): string
    {
        return hash('sha256', $this->aesKey());
    }

    /** Menurunkan AES-256 key dari APP_KEY Laravel yang sedang aktif. */
    private function aesKey(): string
    {
        if (config('hybrid-encryption.aes_key_source', 'app_key') !== 'app_key') {
            throw new EncryptionException('Sumber AES key tidak didukung.');
        }

        $appKey = config('app.key');
        if (!is_string($appKey) || $appKey === '') {
            throw new EncryptionException('APP_KEY Laravel belum dikonfigurasi.');
        }

        // Laravel biasanya menyimpan APP_KEY dalam format base64:....
        $material = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;
        if ($material === false || $material === '') {
            throw new EncryptionException('Format APP_KEY Laravel tidak valid.');
        }

        return hash('sha256', 'aptika-hybrid-encryption|aes-key|' . $material, true);
    }

    /** Memastikan semua field binary payload tersedia. */
    private function validatePayload(array $payload): void
    {
        foreach (['encrypted_key', 'iv', 'tag', 'data'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new DecryptionException("Field {$field} tidak valid.");
            }
        }
    }

    /** Mendekode Base64 secara ketat. */
    private function b64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new DecryptionException('Payload Base64 tidak valid.');
        }

        return $decoded;
    }
}
