<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use function Laravel\Prompts\form;
use function Laravel\Prompts\outro;

class CreateTransaction extends Command
{
    use Concerns\Ynab;

    protected $signature = 'ynab:transaction';
    protected $description = 'Creates a new transaction in YNAB';

    public function handle(): int
    {
        $accounts = $this->getAccounts();
        $payees = $this->getPayees();
        $categories = $this->getCategories();

        $responses = form()
            ->text('Amount', required: true, name: 'amount')
            ->select('Account', options: $accounts, required: true, name: 'account')
            ->search(
                label: 'Payee',
                options: fn (string $value) => strlen($value) > 0
                    ? $payees->filter(fn ($payee) => Str::contains($payee, $value, true))->all()
                    : [],
                name: 'payee'
            )
            ->search(
                label: 'Category',
                options: fn (string $value) => strlen($value) > 0
                    ? $categories->filter(fn ($category) => Str::contains($category, $value, true))->all()
                    : [],
                name: 'category'
            )
            ->text('Memo (optional)', name: 'memo')
            ->select('Flag color (optional)', options: ['none', 'red', 'orange', 'yellow', 'green', 'blue', 'purple'], name: 'flag_color')
            ->select('Cleared', options: ['cleared', 'uncleared'], default: 'uncleared', name: 'cleared')
            ->submit();

        $response = $this->getClient()->post('budgets/' . config('services.ynab.budget_id') . '/transactions', [
            'transaction' => [
                'account_id' => $responses['account'],
                'payee_id' => $responses['payee'],
                'category_id' => $responses['category'],
                'amount' => $responses['amount'] * 1000,
                'memo' => $responses['memo'],
                'flag_color' => $responses['flag_color'] === 'none' ? null : $responses['flag_color'],
                'cleared' => $responses['cleared'],
                'date' => now()->toDateString(),
                'approved' => true,
            ],
        ]);

        if ($response->successful()) {
            $this->info('Expense created successfully');
        } else {
            $this->error('Failed to create expense - ' . $response->json('error.detail'));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection[{string} => {string}]
     */
    public function getAccounts(): Collection
    {
        $response = $this->getClient()->get('budgets/' . config('services.ynab.budget_id') . '/accounts');

        return collect($response->json('data.accounts'))
            ->filter(fn($account) => $account['on_budget'] && !$account['closed'])
            ->mapWithKeys(fn($account) => [$account['id'] => $account['name']]);
    }

    /**
     * @return Collection[{string} => {string}]
     */
    public function getPayees(): Collection
    {
        $response = $this->getClient()->get('budgets/' . config('services.ynab.budget_id') . '/payees');

        return collect($response->json('data.payees'))
            ->reject(fn($payee) => $payee['deleted'])
            ->mapWithKeys(fn($payee) => [$payee['id'] => $payee['name']]);
    }

    /**
     * @return Collection[{string} => {string}]
     */
    public function getCategories(): Collection
    {
        $response = $this->getClient()->get('budgets/' . config('services.ynab.budget_id') . '/categories');

        return collect($response->json('data.category_groups'))
            ->reject(fn($categoryGroup) => $categoryGroup['hidden'] || $categoryGroup['deleted'])
            ->pluck('categories')
            ->flatten(1)
            ->reject(fn($category) => $category['hidden'] || $category['deleted'] || $category['category_group_name'] === 'Credit Card Payments' || $category['category_group_name'] === 'Internal Master Category')
            ->mapWithKeys(fn($category) => [$category['id'] => $category['category_group_name'].': '.$category['name']]);
    }
}
