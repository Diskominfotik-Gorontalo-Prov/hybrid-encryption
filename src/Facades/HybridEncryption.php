<?php

/**
 * Menyediakan sintaks statis untuk memanggil service enkripsi dari container.
 */
namespace Aptika\HybridEncryption\Facades;
use Illuminate\Support\Facades\Facade;
/** Proxy statis untuk HybridEncryptionService yang terdaftar di container. */
class HybridEncryption extends Facade
{
    /** Mengembalikan nama binding container yang digunakan facade ini. */
    protected static function getFacadeAccessor(): string
    {
        return 'hybrid-encryption';
    }
}
