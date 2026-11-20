<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * A server URL the SSRF guard refused. `reason` is one of the fixed codes below. The message names at most the
 * host and the port, never the path, query string, userinfo or a token, so it is safe to show the user who typed
 * the URL. (Connect and verify still collapse every refusal into one generic failure, spec §11 S1.)
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
class UnsafeUrlException extends \RuntimeException
{
    public const INVALID_URL      = 'invalid_url';
    public const HOST_NOT_ALLOWED = 'host_not_allowed';
    public const UNRESOLVABLE     = 'unresolvable';
    public const BLOCKED_ADDRESS  = 'blocked_address';
    public const PRIVATE_NETWORK  = 'private_network';
    public const PORT_NOT_ALLOWED = 'port_not_allowed';

    public function __construct(public readonly string $reason, string $host = '', int $port = 0)
    {
        $shown = $host !== '' ? '"' . $host . '"' : 'the server';
        parent::__construct(match ($reason) {
            self::INVALID_URL      => 'The server URL must be an http:// or https:// address with a host and an optional port, and nothing else.',
            self::HOST_NOT_ALLOWED => sprintf('%s is not on the Matinee host allow-list.', $shown),
            self::UNRESOLVABLE     => sprintf('%s could not be resolved.', $shown),
            self::PRIVATE_NETWORK  => sprintf('%s is on a private network. An administrator must add it to the Matinee private targets first.', $shown),
            self::PORT_NOT_ALLOWED => sprintf('Port %d on %s is not allowed. Use 32400, 443 or 80, or a private target that names the port.', $port, $shown),
            default                => sprintf('%s resolves to a link-local, metadata or reserved address, which is always blocked.', $shown),
        });
    }
}
