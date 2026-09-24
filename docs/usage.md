# Panduan penggunaan

Dokumen ini berisi alur kerja, konfigurasi, pembuatan key, dan integrasi antar
aplikasi Laravel.

## Alur generate key pair

```mermaid
flowchart TD
    G0([Mulai]) --> G1[Input key_id kosong = default]
    G1 --> G2{Pemanggil}
    G2 -->|Artisan| G3[aptika-hybrid-encryption:generate-key-pair]
    G2 -->|Controller| G4[KeyPairService::generate]
    G3 --> G5[Cek key lama]
    G4 --> G5
    G5 -->|Sudah ada| G6{--force atau validasi 2 + 3 = 5?}
    G6 -->|Tidak| G7([Batal, key lama aman])
    G6 -->|Ya| G8[Pilih sumber AES]
    G5 -->|Belum ada| G8
    G8 -->|app_key| G9[Gunakan APP_KEY Laravel]
    G8 -->|generated| G10[AesKeyService membuat AES 32 byte]
    G10 --> G11[Simpan AES key di secret manager aplikasi]
    G9 --> G12[Buat RSA public/private key]
    G11 --> G12
    G12 --> G13[Simpan key ke disk terkonfigurasi]
    G13 --> G14[Cetak atau kembalikan path dan fingerprint]
```

Private key tidak pernah perlu dikirim ke client. AES key generated hanya
ditampilkan sekali dan harus segera diamankan aplikasi.

## Alur encrypt

```mermaid
flowchart TD
    E0([Mulai encrypt]) --> E1[Input data, key_id opsional, AES key opsional]
    E1 --> E2{key_id kosong?}
    E2 -->|Ya| E3[Gunakan key_id default]
    E2 -->|Tidak| E4[Gunakan key_id yang diberikan]
    E3 --> E5[Baca public.pem]
    E4 --> E5
    E1 --> E6{AES key kosong?}
    E6 -->|Ya| E7[Gunakan material 32 byte APP_KEY]
    E6 -->|Tidak| E8[Validasi AES key 32 byte]
    E7 --> E9[AES-256-GCM encrypt]
    E8 --> E9
    E9 --> E10[Hasilkan ciphertext, IV, dan tag]
    E7 --> E11[RSA-OAEP encrypt AES key]
    E8 --> E11
    E5 --> E11
    E11 --> E12[Gabungkan envelope binary]
    E10 --> E12
    E12 --> E13([String AHE3 dikirim])
```

## Alur decrypt

```mermaid
flowchart TD
    D0([Mulai decrypt]) --> D1[Input payload, key_id opsional, AES key opsional]
    D1 --> D2{key_id kosong?}
    D2 -->|Ya| D3[Gunakan key_id default]
    D2 -->|Tidak| D4[Gunakan key_id yang diberikan]
    D3 --> D5[Baca private.pem]
    D4 --> D5
    D1 --> D6[Bongkar string AHE3]
    D6 --> D7[Ambil encrypted_key, IV, tag, dan ciphertext]
    D5 --> D8[RSA-OAEP decrypt AES key]
    D7 --> D8
    D1 --> D9{AES key kosong?}
    D9 -->|Ya| D10[Gunakan material 32 byte APP_KEY]
    D9 -->|Tidak| D11[Validasi AES key yang diberikan]
    D10 --> D12{AES key cocok?}
    D11 --> D12
    D8 --> D12
    D12 -->|Tidak| D13([Gagal])
    D12 -->|Ya| D14[AES-GCM decrypt dan validasi tag]
    D14 --> D15{Tag valid?}
    D15 -->|Tidak| D13
    D15 -->|Ya| D16([Data asli dikembalikan])
```

## Konfigurasi

Publish konfigurasi:

```bash
php artisan vendor:publish --tag=hybrid-encryption-config
```

Konfigurasi utama berada di `config/hybrid-encryption.php`:

```php
return [
    'cipher' => 'aes-256-gcm',
    'rsa_bits' => (int) env('APTIKA_HYBRID_ENCRYPTION_RSA_BITS', 3072),
    'key_storage' => [
        'disk' => env('APTIKA_HYBRID_ENCRYPTION_KEY_DISK', 'local'),
        'visibility' => env('APTIKA_HYBRID_ENCRYPTION_KEY_VISIBILITY', 'private'),
        'public_prefix' => env(
            'APTIKA_HYBRID_ENCRYPTION_PUBLIC_PREFIX',
            'hybrid-encryption/keys/public'
        ),
        'private_prefix' => env(
            'APTIKA_HYBRID_ENCRYPTION_PRIVATE_PREFIX',
            'hybrid-encryption/keys/private'
        ),
    ],
];
```

Public dan private key memakai disk yang sama, tetapi prefix berbeda:

```text
storage/app/hybrid-encryption/keys/public/default/public.pem
storage/app/hybrid-encryption/keys/private/default/private.pem
```

`disk` adalah nama disk Laravel, bukan folder private. `visibility` default
adalah `private`.

## Command

```bash
php artisan aptika-hybrid-encryption:generate-key-pair
php artisan aptika-hybrid-encryption:generate-key-pair users/10/profile
php artisan aptika-hybrid-encryption:generate-key-pair features/bantuan --aes-source=generated
php artisan aptika-hybrid-encryption:generate-aes-key
php artisan aptika-hybrid-encryption:revoke-key-pair
php artisan aptika-hybrid-encryption:revoke-key-pair users/10/profile
```

`key_id` default adalah `default`. Saat key lama akan ditimpa atau dicabut,
command meminta jawaban `5` untuk validasi `2 + 3`, kecuali menggunakan:

```bash
php artisan aptika-hybrid-encryption:generate-key-pair default --force
php artisan aptika-hybrid-encryption:revoke-key-pair default --force
```

Mode `app_key` memakai `APP_KEY` Laravel. Mode `generated` membuat AES key
acak 32 byte, menampilkannya sekali, dan tidak menyimpan file AES.

## Generate dari controller

```php
use Aptika\HybridEncryption\Services\AesKeyService;
use Aptika\HybridEncryption\Services\HybridEncryptionService;
use Aptika\HybridEncryption\Services\KeyPairService;
use Illuminate\Http\Request;

public function generate(
    Request $request,
    KeyPairService $keys,
    AesKeyService $aes,
    HybridEncryptionService $crypto,
) {
    $validated = $request->validate([
        'key_id' => ['nullable', 'string', 'max:190'],
        'aes_source' => ['nullable', 'in:app_key,generated'],
    ]);

    $keyId = trim((string) ($validated['key_id'] ?? '')) ?: 'default';
    $source = $validated['aes_source'] ?? 'app_key';
    $aesKey = $source === 'generated' ? $aes->generate() : null;
    $paths = $keys->generate($keyId);

    return response()->json([
        'success' => true,
        'key_id' => $paths['key_id'],
        'disk' => $paths['disk'],
        'visibility' => $paths['visibility'],
        'public_path' => $paths['public_path'],
        'private_path' => $paths['private_path'],
        'aes_source' => $source,
        'aes_fingerprint' => $crypto->aesKeyFingerprint($aesKey),
        'aes_key' => $aesKey,
    ]);
}
```

Endpoint ini harus dibatasi untuk administrator atau proses internal. Jangan
mengembalikan isi `private.pem`.

## Integrasi antar aplikasi Laravel

Misalnya App1 mengenkripsi data untuk App2:

1. Buat key pair di App2.
2. App1 memakai public key App2 berdasarkan `key_id` yang sama.
3. App1 dan App2 memakai `APP_KEY` yang sama, atau AES generated yang sama.
4. App1 mengirim string `AHE3.` melalui HTTPS.
5. App2 memakai private key untuk decrypt.

Pengirim:

```php
$payload = $crypto->encrypt(
    ['nik' => '750101xxxxxxxxxx', 'nama' => 'Alfandy'],
    'features/antar-project'
);

Http::withBody($payload, 'text/plain')
    ->post('https://app2.test/api/receive');
```

Penerima:

```php
public function receive(Request $request)
{
    return response()->json([
        'success' => true,
        'data' => HybridEncryption::decrypt(
            $request->getContent(),
            'features/antar-project'
        ),
    ]);
}
```
