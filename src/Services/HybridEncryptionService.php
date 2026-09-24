<?php

namespace Aptika\HybridEncryption\Services;

use Aptika\HybridEncryption\Exceptions\DecryptionException;
use Aptika\HybridEncryption\Exceptions\EncryptionException;
use Aptika\HybridEncryption\Exceptions\KeyManagementException;
use JsonException;

/** Service enkripsi yang menggunakan RSA untuk membungkus AES key. */
class HybridEncryptionService
{
    /**
     * Mengenkripsi data berdasarkan key_id.
     *
     * Jika $aesKey kosong, material 32 byte APP_KEY digunakan langsung. Jika diisi,
     * nilainya digunakan sebagai AES key yang diberikan aplikasi.
     */
    public function encrypt(string $keyId, array|string $data, ?string $aesKey = null): string
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

        $plaintext = is_array($data)
            ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $data;
        $resolvedAesKey = $this->resolveAesKey($aesKey);
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, config('hybrid-encryption.cipher', 'aes-256-gcm'), $resolvedAesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new EncryptionException('AES encryption gagal.');
        }

        // RSA hanya membungkus AES key, bukan seluruh data.
        if (!openssl_public_encrypt($resolvedAesKey, $encryptedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new EncryptionException('RSA encryption untuk AES key gagal.');
        }

        return $this->packCompactPayload($encryptedKey, $iv, $tag, $ciphertext);
    }

    /**
     * Mendekripsi payload berdasarkan key_id.
     *
     * $aesKey harus diisi dengan nilai yang sama ketika payload dibuat jika
     * payload menggunakan AES generated/provided.
     */
    public function decrypt(string $keyId, array|string $payload, ?string $aesKey = null): array|string
    {
        $payload = $this->unpackPayload($payload);

        try {
            $pem = app(KeyPairService::class)->privateKey($keyId);
        } catch (KeyManagementException $exception) {
            throw new DecryptionException($exception->getMessage(), previous: $exception);
        }

        $privateKey = openssl_pkey_get_private($pem, '');
        if (!$privateKey) {
            throw new DecryptionException('Private key tidak valid.');
        }

        $encryptedKey = $payload['encrypted_key'];
        $iv = $payload['iv'];
        $tag = $payload['tag'];
        $ciphertext = $payload['data'];
        if (!openssl_private_decrypt($encryptedKey, $recoveredAesKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new DecryptionException('RSA decrypt AES key gagal.');
        }

        // Pastikan AES key dari payload sama dengan key yang diharapkan aplikasi.
        try {
            $expectedAesKey = $this->resolveAesKey($aesKey);
        } catch (EncryptionException $exception) {
            throw new DecryptionException($exception->getMessage(), previous: $exception);
        }
        if (!hash_equals($expectedAesKey, $recoveredAesKey)) {
            throw new DecryptionException('AES key tidak cocok dengan payload.');
        }

        $plaintext = openssl_decrypt($ciphertext, config('hybrid-encryption.cipher', 'aes-256-gcm'), $recoveredAesKey, OPENSSL_RAW_DATA, $iv, $tag);
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

    /** Mengembalikan fingerprint AES tanpa membocorkan AES key. */
    public function aesKeyFingerprint(?string $aesKey = null): string
    {
        return hash('sha256', $this->resolveAesKey($aesKey));
    }

    /** Menentukan AES key dari APP_KEY atau dari input aplikasi. */
    private function resolveAesKey(?string $aesKey): string
    {
        if ($aesKey !== null) {
            return app(AesKeyService::class)->normalize($aesKey);
        }

        $appKey = config('app.key');
        if (!is_string($appKey) || $appKey === '') {
            throw new EncryptionException('APP_KEY Laravel belum dikonfigurasi.');
        }

        $material = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;
        if ($material === false || $material === '') {
            throw new EncryptionException('Format APP_KEY Laravel tidak valid.');
        }

        if (strlen($material) !== 32) {
            throw new EncryptionException('APP_KEY harus menghasilkan 32 byte untuk AES-256.');
        }

        // APP_KEY base64: milik Laravel langsung digunakan sebagai AES-256 key.
        return $material;
    }

    /**
     * Membungkus komponen wajib menjadi satu string opaque.
     *
     * Nama field dan metadata tidak dikirim sebagai JSON. Panjang setiap
     * komponen disimpan sebagai unsigned 32-bit integer agar payload dapat
     * dibongkar kembali tanpa separator yang bisa ambigu.
     */
    private function packCompactPayload(string $encryptedKey, string $iv, string $tag, string $ciphertext): string
    {
        $binary = pack('N', strlen($encryptedKey)) . $encryptedKey
            . pack('N', strlen($iv)) . $iv
            . pack('N', strlen($tag)) . $tag
            . $ciphertext;

        return 'AHE3.' . rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** Mengubah payload kompak atau format JSON lama menjadi komponen binary. */
    private function unpackPayload(array|string $payload): array
    {
        if (is_string($payload) && str_starts_with($payload, 'AHE3.')) {
            return $this->unpackCompactPayload($payload);
        }

        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new DecryptionException('Payload harus berupa string kompak yang valid.');
            }

            if (!is_array($payload)) {
                throw new DecryptionException('Payload JSON harus berupa object.');
            }
        }

        $this->validatePayload($payload);

        return [
            'encrypted_key' => $this->b64($payload['encrypted_key']),
            'iv' => $this->b64($payload['iv']),
            'tag' => $this->b64($payload['tag']),
            'data' => $this->b64($payload['data']),
        ];
    }

    /** Membongkar format AHE3 berdasarkan panjang komponen binary. */
    private function unpackCompactPayload(string $payload): array
    {
        $encoded = substr($payload, 5);
        if ($encoded === '') {
            throw new DecryptionException('Payload kompak tidak valid.');
        }
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($binary === false) {
            throw new DecryptionException('Payload kompak tidak valid.');
        }

        $offset = 0;
        $encryptedKey = $this->readCompactPart($binary, $offset, 'encrypted_key');
        $iv = $this->readCompactPart($binary, $offset, 'iv');
        $tag = $this->readCompactPart($binary, $offset, 'tag');
        $data = substr($binary, $offset);

        if ($data === false || $encryptedKey === '' || $iv === '' || $tag === '') {
            throw new DecryptionException('Payload kompak tidak lengkap.');
        }

        return [
            'encrypted_key' => $encryptedKey,
            'iv' => $iv,
            'tag' => $tag,
            'data' => $data,
        ];
    }

    /** Membaca satu bagian binary yang diawali panjang 32-bit. */
    private function readCompactPart(string $binary, int &$offset, string $field): string
    {
        if (strlen($binary) - $offset < 4) {
            throw new DecryptionException("Payload kompak tidak memiliki panjang {$field}.");
        }

        $length = unpack('Nlength', substr($binary, $offset, 4))['length'];
        $offset += 4;
        if ($length < 1 || strlen($binary) - $offset < $length) {
            throw new DecryptionException("Bagian {$field} pada payload kompak tidak valid.");
        }

        $value = substr($binary, $offset, $length);
        $offset += $length;

        return $value;
    }

    /** Memastikan semua field payload JSON lama tersedia. */
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
