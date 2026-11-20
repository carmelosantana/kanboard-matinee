<?php

namespace Kanboard\Plugin\Matinee\Crypto;

/**
 * A crypto operation failed. Messages are fixed, generic strings: they never carry
 * keys, tokens, ciphertexts or any value derived from them.
 */
class CryptoException extends \RuntimeException
{
}
