<?php

namespace Aptika\HybridEncryption\Services;

use Aptika\HybridEncryption\Exceptions\KeyManagementException;
use Illuminate\Support\Facades\Storage;

/** Mengelola pasangan RSA key yang disimpan pada disk Laravel. */
class KeyPairService
{
    /**
     * Membuat pasangan key untuk key_id tertentu.
     *
     * @return array{key_id: string, disk: string, visibility: string, public_path: string, private_path: string}
     */
    public function generate(string $keyId, bool $force = false): array
    {
        $this->validateKeyId($keyId);
        $paths = $this->paths($keyId);
        $disk = Storage::disk($paths['disk']);

        if (!$force && ($disk->exists($paths['public_path']) || $disk->exists($paths['private_path']))) {
            throw new KeyManagementException("Pasangan key untuk key_id [{$keyId}] sudah ada.");
        }

        $key = openssl_pkey_new([
            'private_key_bits' => config('hybrid-encryption.rsa_bits', 3072),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$key) {
            throw new KeyManagementException('OpenSSL gagal membuat RSA key.');
        }

        if (!openssl_pkey_export($key, $privatePem)) {
            throw new KeyManagementException('Gagal menyimpan private key ke memory.');
        }

        $details = openssl_pkey_get_details($key);
        if (!$details || empty($details['key'])) {
            throw new KeyManagementException('Gagal mengambil public key.');
        }

        $options = ['visibility' => $paths['visibility']];
        if (!$disk->put($paths['private_path'], $privatePem, $options)) {
            throw new KeyManagementException('Gagal menyimpan private key.');
        }
        if (!$disk->put($paths['public_path'], $details['key'], $options)) {
            throw new KeyManagementException('Gagal menyimpan public key.');
        }

        return $paths;
    }

    /** Mengambil isi public key untuk dipakai saat encrypt. */
    public function publicKey(string $keyId): string
    {
        return $this->read($keyId, 'public');
    }

    /** Mengambil isi private key untuk dipakai saat decrypt. */
    public function privateKey(string $keyId): string
    {
        return $this->read($keyId, 'private');
    }

    /** Mengembalikan informasi disk dan path pasangan key. */
    public function paths(string $keyId): array
    {
        $this->validateKeyId($keyId);
        $config = config('hybrid-encryption.key_storage', []);

        return [
            'key_id' => $keyId,
            'disk' => $config['disk'] ?? 'local',
            'visibility' => $config['visibility'] ?? 'private',
            'public_path' => trim($config['public_prefix'] ?? 'hybrid-encryption/keys/public', '/') . '/' . $keyId . '/public.pem',
            'private_path' => trim($config['private_prefix'] ?? 'hybrid-encryption/keys/private', '/') . '/' . $keyId . '/private.pem',
        ];
    }

    /** Membaca key dari disk sesuai jenisnya. */
    private function read(string $keyId, string $type): string
    {
        $paths = $this->paths($keyId);
        $path = $type === 'public' ? $paths['public_path'] : $paths['private_path'];
        $disk = Storage::disk($paths['disk']);

        if (!$disk->exists($path)) {
            throw new KeyManagementException("{$type} key untuk key_id [{$keyId}] tidak ditemukan.");
        }

        return $disk->get($path);
    }

    /** Mencegah key_id keluar dari folder penyimpanan yang ditentukan. */
    private function validateKeyId(string $keyId): void
    {
        if ($keyId === '' || str_contains($keyId, '..') || str_starts_with($keyId, '/') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $keyId)) {
            throw new KeyManagementException('key_id tidak valid. Gunakan huruf, angka, titik, garis bawah, atau garis miring.');
        }
    }
}
