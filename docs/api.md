# API dan service

## `HybridEncryptionService`

Service utama untuk encrypt dan decrypt. Service dapat dipanggil melalui
dependency injection:

```php
use Aptika\HybridEncryption\Services\HybridEncryptionService;

public function store(HybridEncryptionService $crypto)
{
    $payload = $crypto->encrypt('alfandy');

    return response($payload)->header('Content-Type', 'text/plain');
}
```

### `encrypt()`

```php
encrypt(
    array|string $data,
    string $keyId = 'default',
    ?string $aesKey = null
): string
```

Parameter:

| Parameter | Keterangan |
| --- | --- |
| `$data` | Array atau string yang akan dienkripsi. Wajib dan menjadi parameter pertama. |
| `$keyId` | Identitas pasangan RSA key. Kosong/tidak dikirim berarti `default`. |
| `$aesKey` | AES key berformat `base64:` atau 32 byte mentah. Kosong berarti `APP_KEY`. |

Hasilnya string kompak berawalan `AHE3.`.

### `decrypt()`

```php
decrypt(
    array|string $payload,
    string $keyId = 'default',
    ?string $aesKey = null
): array|string
```

`$payload` adalah hasil `encrypt()`. Jika plaintext berisi JSON array/object,
hasil dikembalikan sebagai associative array. Jika plaintext string biasa,
hasil dikembalikan sebagai string.

## `KeyPairService`

Lokasi source: `src/Services/KeyPairService.php`.

```php
use Aptika\HybridEncryption\Services\KeyPairService;

$keys = app(KeyPairService::class);
$result = $keys->generate('users/10/profile');
```

### `generate(string $keyId, bool $force = false): array`

Membuat RSA public/private key dan menyimpannya ke disk. Key ID kosong memakai
`default`. `force=true` mengizinkan penimpaan key lama dan sebaiknya hanya
digunakan saat rotasi terencana.

Return:

```php
[
    'key_id' => 'users/10/profile',
    'disk' => 'local',
    'visibility' => 'private',
    'public_path' => 'hybrid-encryption/keys/public/users/10/profile/public.pem',
    'private_path' => 'hybrid-encryption/keys/private/users/10/profile/private.pem',
]
```

### Method key lainnya

```php
$keys->exists('users/10/profile');
$keys->paths('users/10/profile');
$publicPem = $keys->publicKey('users/10/profile');
$privatePem = $keys->privateKey('users/10/profile');
$keys->remove('users/10/profile');
```

`remove()` menghapus public dan private key. Payload lama yang membutuhkan key
tersebut tidak dapat didekripsi setelah private key dihapus.

## `AesKeyService`

```php
use Aptika\HybridEncryption\Services\AesKeyService;

$aes = app(AesKeyService::class);
$key = $aes->generate();
$binaryKey = $aes->normalize($key);
$fingerprint = $aes->fingerprint($key);
```

- `generate(): string` membuat AES-256 key acak dalam format `base64:`.
- `normalize(string $aesKey): string` menghasilkan material binary 32 byte.
- `fingerprint(string $aesKey): string` menghasilkan SHA-256 untuk verifikasi.

Package tidak menyimpan AES key generated.

## Command

Command didaftarkan oleh `HybridEncryptionServiceProvider`:

```text
aptika-hybrid-encryption:generate-key-pair
aptika-hybrid-encryption:generate-aes-key
aptika-hybrid-encryption:revoke-key-pair
```

Command generate dan revoke memakai `default` jika `key_id` tidak dikirim.
Validasi konfirmasi menggunakan hitungan `2 + 3`, kecuali `--force` digunakan.

## Facade

Facade `Aptika\HybridEncryption\Facades\HybridEncryption` meneruskan method
ke `HybridEncryptionService`:

```php
use Aptika\HybridEncryption\Facades\HybridEncryption;

$payload = HybridEncryption::encrypt('alfandy');
$data = HybridEncryption::decrypt($payload);
```

## Exception

- `EncryptionException`: public key, AES, RSA, atau serialisasi data gagal.
- `DecryptionException`: payload, private key, AES key, RSA, atau authentication tag tidak valid.
- `KeyManagementException`: key ID, file key, atau penyimpanan key bermasalah.
