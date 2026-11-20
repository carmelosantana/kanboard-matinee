<?php

namespace Kanboard\Plugin\Matinee\Crypto;

/**
 * The key store is misconfigured or unsafe (a malformed MATINEE_KEY, an unsafe or unreadable key dir or file).
 * It is configuration, not one server's token: callers leave a server's status alone for it.
 */
final class KeyStoreException extends CryptoException
{
}
