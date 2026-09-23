<?php

/** Namespace exception untuk kegagalan proses enkripsi. */
namespace Aptika\HybridEncryption\Exceptions;
use RuntimeException;
/** Dilempar saat payload tidak dapat dienkripsi. */
class EncryptionException extends RuntimeException {}
