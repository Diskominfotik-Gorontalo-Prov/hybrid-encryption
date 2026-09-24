# Laravel Hybrid Encryption

Package Laravel untuk mengenkripsi data menggunakan AES-256-GCM dan RSA-OAEP.

## Instalasi

```bash
composer require aptika/laravel-hybrid-encryption
php artisan vendor:publish --tag=hybrid-encryption-config
php artisan aptika-hybrid-encryption:generate-key-pair
```

Perintah terakhir membuat key pair untuk `key_id=default`. Private key disimpan
di disk Laravel dengan visibility `private`. Jangan commit atau membagikan
private key.

## Pemakaian cepat

Data adalah parameter pertama. Jika `key_id` tidak dikirim, nilainya `default`.
Jika AES key tidak dikirim, package menggunakan `APP_KEY` Laravel.

```php
use Aptika\HybridEncryption\Facades\HybridEncryption;

$payload = HybridEncryption::encrypt('alfandy');
$data = HybridEncryption::decrypt($payload);
```

Dengan data array:

```php
$payload = HybridEncryption::encrypt([
    'nik' => '750101xxxxxxxxxx',
    'nama' => 'Alfandy',
]);

$data = HybridEncryption::decrypt($payload);
```

Dengan `key_id` dan AES key khusus:

```php
$payload = HybridEncryption::encrypt(
    'alfandy',
    'users/10/profile',
    env('USER_10_AES_KEY')
);

$data = HybridEncryption::decrypt(
    $payload,
    'users/10/profile',
    env('USER_10_AES_KEY')
);
```

Signature utama:

```php
encrypt(array|string $data, string $keyId = 'default', ?string $aesKey = null): string
decrypt(array|string $payload, string $keyId = 'default', ?string $aesKey = null): array|string
```

Hasil `encrypt()` adalah satu string kompak berawalan `AHE3.`. String tersebut
tetap memuat komponen kriptografis yang diperlukan untuk decrypt, tetapi tidak
menampilkan metadata sebagai JSON.

## Command utama

```bash
# Membuat key pair default
php artisan aptika-hybrid-encryption:generate-key-pair

# Membuat key pair untuk user/fitur tertentu
php artisan aptika-hybrid-encryption:generate-key-pair users/10/profile

# Membuat AES key mandiri
php artisan aptika-hybrid-encryption:generate-aes-key

# Mencabut key pair default
php artisan aptika-hybrid-encryption:revoke-key-pair
```

Jika key lama akan ditimpa atau revoke ingin dilakukan tanpa konfirmasi:

```bash
php artisan aptika-hybrid-encryption:generate-key-pair default --force
php artisan aptika-hybrid-encryption:revoke-key-pair default --force
```

Tanpa `--force`, operasi berisiko meminta validasi hitungan sederhana `2 + 3`.
Jawaban selain `5` membatalkan perubahan.

## Konfigurasi default

```dotenv
APTIKA_HYBRID_ENCRYPTION_RSA_BITS=3072
APTIKA_HYBRID_ENCRYPTION_KEY_DISK=local
APTIKA_HYBRID_ENCRYPTION_KEY_VISIBILITY=private
APTIKA_HYBRID_ENCRYPTION_PUBLIC_PREFIX=hybrid-encryption/keys/public
APTIKA_HYBRID_ENCRYPTION_PRIVATE_PREFIX=hybrid-encryption/keys/private
```

Konfigurasi lengkap berada di `config/hybrid-encryption.php`.

## Dokumentasi lengkap

- [Panduan penggunaan, flowchart, konfigurasi, dan integrasi antar aplikasi](docs/usage.md)
- [Dokumentasi service, facade, command, dan exception](docs/api.md)
- [Penjelasan algoritma, AES/RSA, key, dan batasan keamanan](docs/security.md)
- [Referensi sumber resmi kriptografi](docs/security-references.md)

## Testing

```bash
composer test
```

## Lisensi

MIT
