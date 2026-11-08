<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Http\Client\RequestException;
use Proxmox\Proxmox;
use Proxmox\Support\ResourceData;
use Proxmox\Support\ResponseData;

/**
 * Reads and formats information from the QEMU guest agent.
 *
 * @internal
 */
final readonly class VirtualMachineGuestAgent
{
    public function __construct(private Proxmox $client, private string $path) {}

    /**
     * @return array{ipv4: list<string>|null, ipv6: list<string>|null}
     */
    public function networkInfo(): array
    {
        $interfaces = $this->run('network-get-interfaces');

        /** @var list<string>|null $ipv4 */
        $ipv4 = null;
        /** @var list<string>|null $ipv6 */
        $ipv6 = null;

        if ($interfaces !== null) {
            $ipv4 = [];
            $ipv6 = [];

            foreach ($interfaces as $interface) {
                if (! is_array($interface) || ! is_array($interface['ip-addresses'] ?? null)) {
                    continue;
                }

                foreach ($interface['ip-addresses'] as $address) {
                    $ipAddress = is_array($address) ? ($address['ip-address'] ?? null) : null;

                    if (is_string($ipAddress)
                        && filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
                        && ! self::isLoopbackOrLinkLocalAddress($ipAddress)) {
                        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                            $ipv4[] = $ipAddress;
                        } elseif (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                            $ipv6[] = $ipAddress;
                        }
                    }
                }
            }

            $ipv4 = array_values(array_unique($ipv4));
            $ipv6 = array_values(array_unique($ipv6));
        }

        return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
    }

    /**
     * @return list<array{mountpoint: string, type: string|null, total: int, used: int, available: int}>|null
     */
    public function filesystems(): ?array
    {
        $result = $this->run('get-fsinfo');

        if ($result === null) {
            return null;
        }

        $filesystems = [];

        foreach ($result as $filesystem) {
            if (! is_array($filesystem) || ! is_string($filesystem['mountpoint'] ?? null) || ! array_key_exists('total-bytes', $filesystem)) {
                continue;
            }

            $total = ResourceData::integer($filesystem['total-bytes']);
            $used = min($total, ResourceData::integer($filesystem['used-bytes'] ?? 0));

            $filesystems[] = [
                'mountpoint' => $filesystem['mountpoint'],
                'type' => is_string($filesystem['type'] ?? null) ? $filesystem['type'] : null,
                'total' => ResourceData::gibibytes($total),
                'used' => ResourceData::gibibytes($used),
                'available' => ResourceData::gibibytes($total - $used),
            ];
        }

        return $filesystems;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function run(string $command): ?array
    {
        try {
            $response = ResponseData::attributes($this->client->get($this->path.'/'.$command));
        } catch (RequestException $exception) {
            // Proxmox answers 500 when the guest agent is not running inside the VM.
            if ($exception->response->serverError()) {
                return null;
            }

            throw $exception;
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private static function isLoopbackOrLinkLocalAddress(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $firstOctet = ord($packed[0]);

            return $firstOctet === 127
                || ($firstOctet === 169 && ord($packed[1]) === 254);
        }

        return $packed === str_repeat("\0", 15)."\1"
            || (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
    }
}
