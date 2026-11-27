<?php

declare(strict_types=1);

namespace App\Models;

use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount as BaseConnectedAccount;

class ConnectedAccount extends BaseConnectedAccount
{
    /** @var string */
    protected $table = 'connected_accounts';

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable([
            'enable_family_matching',
            'cached_profile_data',
            'last_synced_at',
        ]);

        $this->mergeCasts([
            'enable_family_matching' => 'boolean',
            'cached_profile_data' => 'array',
            'last_synced_at' => 'datetime',
        ]);

        parent::__construct($attributes);
    }
}
