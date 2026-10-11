<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Support\JsonValueGuard;
use RuntimeException;

/**
 * Decides which addresses an egg source may request.
 *
 * - Only host names made of plain DNS labels, or literal IP addresses, are accepted. Forms that libcurl would
 *   parse itself (0xa9fea9fe, 2852039166, 127.1, a trailing dot, scope ids) are refused.
 * - Every address a host name resolves to is checked, and a name that resolves to nothing is refused.
 * - IPv4-mapped (::ffff:0:0/96), NAT64 (64:ff9b::/96) and IPv4-compatible (::/96) addresses are checked as the
 *   IPv4 address they carry.
 * - Link-local, metadata and unspecified addresses are always refused. Private, loopback and other reserved
 *   addresses are refused unless the extension setting "allow_private_hosts" is enabled. It is off by default.
 * - URLs with a user name or password are refused.
 *
 * The validated addresses are returned so the client can pin the connection to one of them.
 */
final readonly class EggSourceAddressPolicy
{
    /** The extension whose settings hold the private host switch. */
    public const EXTENSION_ID = 'ramjet-eggs';

    /** Addresses that are never allowed, regardless of the private host setting. */
    public const ALWAYS_BLOCKED_ADDRESSES = ['100.100.100.200', 'fd00:ec2::254'];

    /** A DNS host name: labels of letters, digits and hyphens; the last label starts with a letter. */
    private const HOST_NAME_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    public function __construct(
        private HostResolver $resolver,
        private ExtensionRepository $extensions,
    ) {}

    /**
     * @return list<string> the validated addresses of the host (never empty)
     *
     * @throws RuntimeException
     */
    public function assertAllowed(string $url): array
    {
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('The egg source address must not contain a user name or password.');
        }

        $host = SourceUrl::part($url, PHP_URL_HOST);
        if ($host === '') {
            throw new RuntimeException('The egg source address does not contain a valid host.');
        }

        $host = mb_strtolower(trim($host, '[]'));
        if (str_contains($host, '%') || (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match(self::HOST_NAME_PATTERN, $host))) {
            throw new RuntimeException('The egg source address does not contain a valid host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $addresses = array_values(array_unique($this->resolver->resolve($host)));
            if ($addresses === []) {
                throw new RuntimeException('The egg source host could not be resolved.');
            }
        }

        $allowPrivate = $this->allowPrivateHosts();
        foreach ($addresses as $address) {
            $this->assertAddressAllowed($address, $allowPrivate);
        }

        return $addresses;
    }

    public function allowPrivateHosts(): bool
    {
        return JsonValueGuard::boolean($this->extensions->settings(self::EXTENSION_ID)->get('allow_private_hosts', false));
    }

    /**
     * @throws RuntimeException
     */
    private function assertAddressAllowed(string $address, bool $allowPrivate): void
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            throw new RuntimeException('The egg source address resolves to an invalid address.');
        }

        $binary = $this->unwrap($binary);

        if ($this->isAlwaysBlocked($binary)) {
            throw new RuntimeException('The egg source address resolves to a link-local or metadata address, which is not allowed.');
        }

        // SAFETY: inet_pton accepted this address, so inet_ntop returns its textual form.
        $plain = (string) inet_ntop($binary);
        if (! $allowPrivate && ! filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Private and reserved addresses are not allowed for egg sources.');
        }
    }

    /**
     * Returns the IPv4 address carried by a mapped, NAT64 or IPv4-compatible IPv6 address, and the input otherwise.
     * The loopback (::1) and unspecified (::) addresses are not IPv4-compatible and are kept as they are.
     */
    private function unwrap(string $binary): string
    {
        $bytes = $this->bytesOf($binary);
        if (count($bytes) !== 16) {
            return $binary;
        }

        $head = array_slice($bytes, 0, 12);
        $tail = array_slice($bytes, 12);

        $mapped = $head === [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0xFF, 0xFF];
        $nat64 = array_slice($head, 0, 4) === [0x00, 0x64, 0xFF, 0x9B] && array_slice($head, 4) === array_fill(0, 8, 0);
        $compatible = $head === array_fill(0, 12, 0) && $tail !== [0, 0, 0, 0] && $tail !== [0, 0, 0, 1];

        return ($mapped || $nat64 || $compatible) ? pack('C*', ...$tail) : $binary;
    }

    private function isAlwaysBlocked(string $binary): bool
    {
        $bytes = $this->bytesOf($binary);

        // IPv4: 0.0.0.0/8 (includes the unspecified address) and the link-local 169.254.0.0/16 (includes the metadata
        // address).
        if (count($bytes) === 4 && ($bytes[0] === 0 || ($bytes[0] === 169 && $bytes[1] === 254))) {
            return true;
        }

        // IPv6: the unspecified address, and link-local fe80::/10.
        if (count($bytes) === 16 && ($bytes === array_fill(0, 16, 0) || ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0x80))) {
            return true;
        }

        foreach (self::ALWAYS_BLOCKED_ADDRESSES as $blocked) {
            if ($binary === inet_pton($blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bytes of a packed address. Addresses are binary, so they are measured and compared as bytes.
     *
     * @return list<int>
     */
    private function bytesOf(string $binary): array
    {
        // SAFETY: unpack with the "C" format yields one integer per byte of the packed address.
        /** @var list<int> $bytes */
        $bytes = array_values(unpack('C*', $binary) ?: []);

        return $bytes;
    }
}
