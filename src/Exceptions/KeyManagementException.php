<?php

/** Exception untuk kegagalan membuat atau membaca pasangan RSA key. */
namespace Aptika\HybridEncryption\Exceptions;

use RuntimeException;

/** Dilempar saat key_id atau penyimpanan pasangan key tidak valid. */
class KeyManagementException extends RuntimeException {}
