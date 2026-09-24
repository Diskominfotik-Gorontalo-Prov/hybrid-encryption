<?php

namespace Aptika\HybridEncryption\Services;

use Aptika\HybridEncryption\Exceptions\EncryptionException;

/** Membuat dan memeriksa AES-256 key yang diberikan aplikasi. */
class AesKeyService
{
    /** Membuat AES-256 key acak dalam format base64: yang siap dipakai. */
    public function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** Menghasilkan fingerprint AES tanpa menampilkan isi key. */
    public function fingerprint(string $aesKey): string
    {
        return hash('sha256', $this->normalize($aesKey));
    }

    /** Memastikan key berisi tepat 32 byte untuk AES-256. */
    public function normalize(string $aesKey): string
    {
        if (str_starts_with($aesKey, 'base64:')) {
            $decoded = base64_decode(substr($aesKey, 7), true);
            if ($decoded === false) {
                throw new EncryptionException('Format AES key Base64 tidak valid.');
            }
            $aesKey = $decoded;
        }

        if (strlen($aesKey) !== 32) {
            throw new EncryptionException('AES key harus berukuran 32 byte untuk AES-256.');
        }

        return $aesKey;
    }
}
