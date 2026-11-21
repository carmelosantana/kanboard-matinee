<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * The secure name of a Plex server typed as `https://<IPv4>[:port]`. Plex serves a `*.<hash>.plex.direct` wildcard
 * certificate, which never covers the bare IP, so that form can never pass TLS verification. The name that does is
 * `https://<a-b-c-d>.<hash>.plex.direct:<port>`: the hash comes from the certificate, the IP and port from what the
 * owner typed.
 *
 * Only the wildcard counts. A certificate naming one specific `<label>.<hash>.plex.direct` host is refused, even the
 * typed IP's own label: Plex issues only the wildcard, and a specific name for another IP would never cover the name
 * Matinee builds, so the verified request would fail anyway. Refusing it here means no token is ever sent towards a
 * name the certificate does not cover.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexDirect
{
    /** Plex's certificate name: a wildcard over one 32-lowercase-hex hash. */
    private const WILDCARD = '/\A\*\.([0-9a-f]{32})\.plex\.direct\z/';

    /** A rewritten base URL (its IP label and port are then compared with the typed base). */
    private const SECURE_NAME = '#\Ahttps://[0-9-]+\.([0-9a-f]{32})\.plex\.direct:[1-9][0-9]{0,4}\z#';

    /**
     * The IP and effective port of a base URL that gets a discovery: https with an IPv4 literal host. Anything else
     * (http, a hostname, an IPv6 literal, not a base URL) is null and is used as typed.
     *
     * @return array{ip: string, port: int}|null
     */
    public static function ipv4Endpoint(string $base): ?array
    {
        if (! UrlGuard::isValidBaseUrl($base)) {
            return null;
        }
        $parts = (array) parse_url($base);
        $host = rtrim(strtolower((string) $parts['host']), '.');
        if (strtolower((string) $parts['scheme']) !== 'https' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        return ['ip' => $host, 'port' => (int) ($parts['port'] ?? 443)];
    }

    /**
     * The secure name for $base from the leaf certificate's names, or null when the base gets no discovery or the
     * names hold no single plex.direct wildcard.
     *
     * @param list<string> $names the leaf's subject CN and DNS SANs
     */
    public static function fromCertificate(string $base, array $names): ?string
    {
        $endpoint = self::ipv4Endpoint($base);
        if ($endpoint === null) {
            return null;
        }
        $hashes = [];
        foreach ($names as $name) {
            if (preg_match(self::WILDCARD, $name, $m) === 1) {
                $hashes[$m[1]] = true;
            }
        }
        return count($hashes) === 1 ? self::secureName($endpoint, (string) array_key_first($hashes)) : null;
    }

    /** True only for `https://<typed IP dashed>.<32 lowercase hex>.plex.direct:<typed port>`. */
    public static function isSecureNameFor(string $base, string $candidate): bool
    {
        $endpoint = self::ipv4Endpoint($base);
        return $endpoint !== null
            && preg_match(self::SECURE_NAME, $candidate, $m) === 1
            && $candidate === self::secureName($endpoint, $m[1]);
    }

    /**
     * The subject CN and DNS subject-alternative names of one CURLINFO_CERTINFO entry (curl spells the subject
     * `CN = x` or `CN=x`, and the SANs `DNS:x, IP Address:y`).
     *
     * @param array<string, string> $certificate
     * @return list<string>
     */
    public static function certificateNames(array $certificate): array
    {
        $names = [];
        if (preg_match('/(?:\A|[,\/])\s*CN\s*=\s*([^,\/]+)/', (string) ($certificate['Subject'] ?? ''), $m) === 1) {
            $names[] = trim($m[1]);
        }
        foreach (explode(',', (string) ($certificate['X509v3 Subject Alternative Name'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = trim(substr($entry, 4));
            }
        }
        return $names;
    }

    /** @param array{ip: string, port: int} $endpoint */
    private static function secureName(array $endpoint, string $hash): string
    {
        return 'https://' . str_replace('.', '-', $endpoint['ip']) . '.' . $hash . '.plex.direct:' . $endpoint['port'];
    }
}
