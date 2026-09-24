<?php

return [
    // Cipher authenticated encryption untuk isi payload.
    'cipher' => 'aes-256-gcm',

    // Ukuran RSA key yang digunakan oleh command generate-key-pair.
    'rsa_bits' => (int) env('APTIKA_HYBRID_ENCRYPTION_RSA_BITS', 3072),

    // Penyimpanan pasangan key berdasarkan key_id, misalnya users/10/profile.
    'key_storage' => [
        'disk' => env('APTIKA_HYBRID_ENCRYPTION_KEY_DISK', 'local'),
        'visibility' => env('APTIKA_HYBRID_ENCRYPTION_KEY_VISIBILITY', 'private'),
        'public_prefix' => env('APTIKA_HYBRID_ENCRYPTION_PUBLIC_PREFIX', 'hybrid-encryption/keys/public'),
        'private_prefix' => env('APTIKA_HYBRID_ENCRYPTION_PRIVATE_PREFIX', 'hybrid-encryption/keys/private'),
    ],
];
