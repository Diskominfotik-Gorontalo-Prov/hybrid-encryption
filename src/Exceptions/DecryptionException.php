<?php

/** Namespace exception untuk kegagalan dekripsi dan validasi payload. */
namespace Aptika\HybridEncryption\Exceptions;
use RuntimeException;
/** Dilempar saat payload tidak dapat divalidasi atau didekripsi. */
class DecryptionException extends RuntimeException {}
