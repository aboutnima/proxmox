<?php

declare(strict_types=1);

namespace Proxmox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Proxmox\ProxmoxManager;

/**
 * A named Proxmox connection. Nodes and VMs are fetched through the API.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $api_token_id
 * @property string $api_token_secret
 * @property int $timeout
 * @property int $connect_timeout
 * @property bool $verify_ssl
 * @property string|null $ca_bundle
 * @property int $retry_times
 * @property int $retry_sleep
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Cluster extends Model
{
    protected $table = 'proxmox_clusters';

    protected $fillable = [
        'name',
        'url',
        'api_token_id',
        'api_token_secret',
        'timeout',
        'connect_timeout',
        'verify_ssl',
        'ca_bundle',
        'retry_times',
        'retry_sleep',
    ];

    protected $hidden = ['api_token_secret'];

    protected $attributes = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'retry_times' => 0,
        'retry_sleep' => 100,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token_secret' => 'encrypted',
            'timeout' => 'integer',
            'connect_timeout' => 'integer',
            'verify_ssl' => 'boolean',
            'retry_times' => 'integer',
            'retry_sleep' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Cluster $cluster): void {
            $manager = app(ProxmoxManager::class);
            $manager->purge($cluster->name);

            if ($cluster->wasChanged('name') && is_string($original = $cluster->getOriginal('name'))) {
                $manager->purge($original);
            }
        });

        static::deleted(fn (Cluster $cluster) => app(ProxmoxManager::class)->purge($cluster->name));
    }

    /**
     * Connection settings for the HTTP client. Contains the decrypted secret.
     *
     * @return array{url: string, api_token_id: string, api_token_secret: string, timeout: int, connect_timeout: int, verify_ssl: bool, ca_bundle: string|null, retry: array{times: int, sleep: int}}
     */
    public function toConnectionConfig(): array
    {
        return [
            'url' => $this->url,
            'api_token_id' => $this->api_token_id,
            'api_token_secret' => $this->api_token_secret,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connect_timeout,
            'verify_ssl' => $this->verify_ssl,
            'ca_bundle' => $this->ca_bundle,
            'retry' => ['times' => $this->retry_times, 'sleep' => $this->retry_sleep],
        ];
    }
}
