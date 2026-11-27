<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use JoelButcher\Socialstream\Socialstream;
use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount as BaseConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Socialstream::useConnectedAccountModel(ConnectedAccount::class);
        Gate::policy(ConnectedAccount::class, ConnectedAccountPolicy::class);

        $applyGenealogyDiscoverySchema = static function (BaseConnectedAccount $account): void {
            $account->mergeFillable([
                'enable_family_matching',
                'cached_profile_data',
                'last_synced_at',
            ]);

            $account->mergeCasts([
                'enable_family_matching' => 'boolean',
                'cached_profile_data' => 'array',
                'last_synced_at' => 'datetime',
            ]);
        };

        BaseConnectedAccount::created($applyGenealogyDiscoverySchema);
        BaseConnectedAccount::retrieved($applyGenealogyDiscoverySchema);
    }
}
