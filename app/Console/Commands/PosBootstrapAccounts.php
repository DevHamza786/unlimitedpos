<?php

namespace App\Console\Commands;

use App\Account;
use App\AccountType;
use App\Business;
use App\BusinessLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PosBootstrapAccounts extends Command
{
    protected $signature = 'pos:bootstrap-accounts
        {business_id : Business ID}
        {--created-by=1 : User ID to mark as creator}
        {--dry-run : Show what would be created}
    ';

    protected $description = 'Create a basic chart of accounts and link default payment accounts per location (cash/card/square/bank_transfer/cheque/advance).';

    public function handle(): int
    {
        $businessId = (int) $this->argument('business_id');
        $createdBy = (int) $this->option('created-by');
        $dry = (bool) $this->option('dry-run');

        $biz = Business::find($businessId);
        if (! $biz) {
            $this->error('Business not found');
            return self::FAILURE;
        }

        $locations = BusinessLocation::where('business_id', $businessId)->get();
        if ($locations->isEmpty()) {
            $this->error('No business locations found');
            return self::FAILURE;
        }

        $plan = $this->plan($businessId);
        $this->info('Account types to ensure: '.count($plan['types']));
        $this->info('Accounts to ensure: '.count($plan['accounts']));

        if ($dry) {
            foreach ($plan['types'] as $t) {
                $this->line('TYPE: '.$t['parent'].' > '.$t['name']);
            }
            foreach ($plan['accounts'] as $a) {
                $this->line('ACCT: '.$a['name'].' ('.$a['number'].')');
            }
            $this->warn('Dry-run only. No changes made.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($businessId, $createdBy, $locations, $plan) {
            $typeIds = [];
            foreach ($plan['types'] as $t) {
                $parentId = null;
                if ($t['parent'] !== null) {
                    $parentId = AccountType::firstOrCreate([
                        'business_id' => $businessId,
                        'name' => $t['parent'],
                        'parent_account_type_id' => null,
                    ])->id;
                }
                $type = AccountType::firstOrCreate([
                    'business_id' => $businessId,
                    'name' => $t['name'],
                    'parent_account_type_id' => $parentId,
                ]);
                $typeIds[$t['key']] = $type->id;
            }

            $accountIds = [];
            foreach ($plan['accounts'] as $a) {
                $acct = Account::firstOrCreate(
                    [
                        'business_id' => $businessId,
                        'account_number' => $a['number'],
                    ],
                    [
                        'name' => $a['name'],
                        'created_by' => $createdBy,
                        'account_type_id' => $typeIds[$a['type_key']] ?? null,
                        'note' => 'Auto-created by pos:bootstrap-accounts',
                        'is_closed' => 0,
                    ]
                );
                $accountIds[$a['key']] = $acct->id;
            }

            foreach ($locations as $loc) {
                $current = ! empty($loc->default_payment_accounts)
                    ? json_decode($loc->default_payment_accounts, true)
                    : [];
                $current = is_array($current) ? $current : [];

                $mapping = [
                    'cash' => 'cash',
                    'card' => 'card',
                    'square' => 'square',
                    'bank_transfer' => 'bank',
                    'cheque' => 'cheque',
                    'advance' => 'advance',
                ];

                foreach ($mapping as $method => $acctKey) {
                    $current[$method] = [
                        'is_enabled' => 1,
                        'account' => $accountIds[$acctKey] ?? null,
                    ];
                }

                $loc->default_payment_accounts = json_encode($current);
                $loc->save();
            }
        });

        $this->info('Chart of accounts ensured and default payment accounts linked.');
        return self::SUCCESS;
    }

    /**
     * @return array{types: array<int,array{key:string,parent:?string,name:string}>, accounts: array<int,array{key:string,name:string,number:string,type_key:string}>}
     */
    private function plan(int $businessId): array
    {
        // Minimal, practical COA for POS flows.
        $types = [
            ['key' => 'asset_cash', 'parent' => 'Assets', 'name' => 'Cash & equivalents'],
            ['key' => 'asset_bank', 'parent' => 'Assets', 'name' => 'Bank accounts'],
            ['key' => 'asset_receivable', 'parent' => 'Assets', 'name' => 'Accounts receivable'],
            ['key' => 'asset_inventory', 'parent' => 'Assets', 'name' => 'Inventory'],
            ['key' => 'liability_tax', 'parent' => 'Liabilities', 'name' => 'Taxes payable'],
            ['key' => 'income_sales', 'parent' => 'Income', 'name' => 'Sales revenue'],
            ['key' => 'cogs', 'parent' => 'Expenses', 'name' => 'Cost of goods sold'],
            ['key' => 'expense_purchase', 'parent' => 'Expenses', 'name' => 'Purchases'],
        ];

        $accounts = [
            ['key' => 'cash', 'name' => 'Cash on Hand', 'number' => '1010', 'type_key' => 'asset_cash'],
            ['key' => 'bank', 'name' => 'Bank', 'number' => '1020', 'type_key' => 'asset_bank'],
            ['key' => 'card', 'name' => 'Card Clearing', 'number' => '1030', 'type_key' => 'asset_bank'],
            ['key' => 'square', 'name' => 'Square Clearing', 'number' => '1035', 'type_key' => 'asset_bank'],
            ['key' => 'cheque', 'name' => 'Cheques Received', 'number' => '1040', 'type_key' => 'asset_receivable'],
            ['key' => 'advance', 'name' => 'Customer Advances', 'number' => '2010', 'type_key' => 'liability_tax'],
        ];

        return compact('types', 'accounts');
    }
}

