<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends ApiController
{
    public function categories(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::query()->with('account')
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get()
            ->map(fn (ExpenseCategory $category) => $category->toApiArray());

        return $this->ok($categories);
    }

    public function storeCategory(Request $request, AuditService $audit): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:expense_categories,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'string', Rule::exists('accounts', 'public_id')],
        ]);

        $account = Account::query()->where('public_id', $data['account_id'])->firstOrFail();
        $this->assertExpenseAccount($account);

        $category = ExpenseCategory::query()->create([
            'code' => $data['code'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'account_id' => $account->id,
            'is_active' => true,
        ]);
        $audit->log('expense_category.created', $category, null, ['code' => $category->code], $this->actorId());

        return $this->created($category->load('account')->toApiArray());
    }

    public function updateCategory(Request $request, ExpenseCategory $category, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'account_id' => ['sometimes', 'string', Rule::exists('accounts', 'public_id')],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['account_id'])) {
            $account = Account::query()->where('public_id', $data['account_id'])->firstOrFail();
            $this->assertExpenseAccount($account);
            $data['account_id'] = $account->id;
        }

        $before = $category->only(array_keys($data));
        $category->update($data);
        $audit->log('expense_category.updated', $category, $before, $data, $this->actorId());

        return $this->ok($category->refresh()->load('account')->toApiArray());
    }

    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $expenses = Expense::query()->with(['category.account', 'cashAccount', 'journalEntry'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('expense_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('expense_date', '<=', $to))
            ->when($request->query('search'), fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('payee_name', 'like', "%{$search}%");
            }))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $expenses->getCollection()->transform(fn (Expense $expense) => $expense->toApiArray());

        return $this->paginated($expenses);
    }

    public function show(Expense $expense): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        return $this->ok($expense->load('category.account', 'cashAccount', 'journalEntry')->toApiArray());
    }

    public function store(Request $request, ExpenseService $expenses): JsonResponse
    {
        $expense = $expenses->create($this->validated($request), $this->branchOrFail(), $this->actorId());

        return $this->created($expense->toApiArray());
    }

    public function update(Request $request, Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        $data = $this->validated($request, partial: true);

        return $this->ok($expenses->update($expense, $data, $this->actorId())->toApiArray());
    }

    public function submit(Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        return $this->ok($expenses->submit($expense, $this->actorId())->toApiArray());
    }

    public function approve(Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        return $this->ok($expenses->approve($expense, $this->actorId())->toApiArray());
    }

    public function reject(Request $request, Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($expenses->reject($expense, $data['reason'], $this->actorId())->toApiArray());
    }

    public function reopen(Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        $user = request()->user();
        abort_unless($expense->created_by === $user?->id || $user?->can('expenses.approve'), 403, 'Only the creator or an approver can reopen this expense.');

        return $this->ok($expenses->reopen($expense, $this->actorId())->toApiArray());
    }

    public function cancel(Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        return $this->ok($expenses->cancel($expense, $this->actorId())->toApiArray());
    }

    public function reverse(Request $request, Expense $expense, ExpenseService $expenses): JsonResponse
    {
        $this->authorizeBranchId($expense->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($expenses->reverse($expense, $data['reason'], $this->actorId())->toApiArray());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'category_id' => [$required, 'string', Rule::exists('expense_categories', 'public_id')],
            'expense_date' => [$required, 'date'],
            'amount_minor' => [$required, 'integer', 'min:1'],
            'tax_minor' => ['nullable', 'integer', 'min:0'],
            'tax_applied' => ['sometimes', 'boolean'],
            'currency' => [$required, 'string', 'size:3', Rule::exists('currencies', 'code')],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'description' => [$required, 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'payee_type' => ['nullable', Rule::in(['none', 'supplier'])],
            'payee_id' => ['nullable', 'string'],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'cash_account_id' => ['nullable', 'string', Rule::exists('cash_accounts', 'public_id')],
            'submit' => ['nullable', 'boolean'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        return $data;
    }

    private function assertExpenseAccount(Account $account): void
    {
        if ($account->type !== 'expense' || ! $account->is_postable || ! $account->is_active) {
            throw new \DomainException("Account {$account->code} must be an active, postable expense account.");
        }
    }
}
