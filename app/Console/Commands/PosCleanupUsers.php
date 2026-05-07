<?php

namespace App\Console\Commands;

use App\Contact;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PosCleanupUsers extends Command
{
    protected $signature = 'pos:cleanup-users
        {business_id : Business ID to clean}
        {--keep-user-id= : Extra user ID to keep (repeat by comma)}
        {--dry-run : Show what would be deleted}
    ';

    protected $description = 'Soft-delete non-customer portal users for a business (keeps default customer contact + specified users).';

    public function handle(): int
    {
        $businessId = (int) $this->argument('business_id');
        if ($businessId <= 0) {
            $this->error('Invalid business_id');
            return self::FAILURE;
        }

        $keepIds = [];
        $rawKeep = (string) ($this->option('keep-user-id') ?? '');
        if ($rawKeep !== '') {
            $keepIds = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $rawKeep))));
        }

        // Always keep currently authenticated user if running via web,
        // but for CLI we only keep explicit IDs.
        $keepIds = array_unique(array_filter($keepIds, fn ($v) => $v > 0));

        $query = User::whereNull('deleted_at')
            ->where('business_id', $businessId);

        if (! empty($keepIds)) {
            $query->whereNotIn('id', $keepIds);
        }

        // Don't touch system types if present; only staff users.
        $query->whereIn('user_type', ['user', 'admin', 'superadmin']);

        $candidates = $query->get(['id', 'username', 'first_name', 'last_name', 'email', 'user_type']);

        $this->info('Users to remove (soft-delete): '.$candidates->count());
        foreach ($candidates as $u) {
            $this->line(sprintf('- #%d %s (%s) [%s]', $u->id, $u->username, $u->user_type, (string) $u->email));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run only. No changes made.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates) {
            foreach ($candidates as $u) {
                $u->delete();
            }
        });

        // Note: customers are stored in contacts; not deleted here.
        $defaultCustomer = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_default', 1)
            ->first();

        $this->info('Default customer contact kept: '.($defaultCustomer?->name ?? '(none)'));

        return self::SUCCESS;
    }
}

