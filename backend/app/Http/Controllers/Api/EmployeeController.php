<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Parties\Models\EmployeeCustody;
use App\Modules\Parties\Services\EmployeeFinanceService;
use App\Modules\Parties\Services\PartyProfileService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $employees = Employee::query()->with('branch')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->boolean('include_inactive') === false, fn ($query) => $query->where('is_active', true))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['name_ar', 'name_en', 'internal_code'], $search))
            ->orderBy('internal_code')->get()
            ->map(fn (Employee $e) => $e->toApiArray());

        return $this->ok($employees);
    }

    public function store(Request $request, EmployeeFinanceService $service): JsonResponse
    {
        $data = $request->validate([
            'internal_code' => ['required', 'string', 'max:40', 'unique:employees,internal_code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(Employee::categories())],
            'branch_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $branch = $this->resolveBranch($data['branch_id'] ?? null);

        $employee = $service->createEmployee($data, $branch, $this->actorId());

        return $this->created($employee->toApiArray());
    }

    public function show(Request $request, Employee $employee, PartyProfileService $profiles): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        [$from, $to] = $this->profileRange($request);

        return $this->ok($profiles->employee($employee, $this->branch(), $from, $to));
    }

    public function update(Request $request, Employee $employee, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', Rule::in(Employee::categories())],
            'user_id' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->ok($service->updateEmployee($employee, $data, $this->actorId())->toApiArray());
    }

    public function deactivate(Employee $employee, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        return $this->ok($service->deactivateEmployee($employee, $this->actorId())->toApiArray());
    }

    public function statement(Employee $employee, Request $request, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return $this->ok($service->employeeStatement($employee->id, $data['from'], $data['to']));
    }

    public function requestAdvance(Request $request, Employee $employee, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'employee_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'request_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $data['employee_id'] = $employee->public_id;

        return $this->created($service->requestAdvance($data, $this->branchOrFail(), $this->actorId())->toApiArray());
    }

    public function approveAdvance(Employee $employee, EmployeeAdvance $advance, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        return $this->ok($service->approveAdvance($advance, $this->actorId())->toApiArray());
    }

    public function disburseAdvance(Request $request, Employee $employee, EmployeeAdvance $advance, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'cash_account_id' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'disbursed_date' => ['nullable', 'date'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return $this->ok($service->disburseAdvance($advance, $this->branchOrFail(), $data, $this->actorId())->toApiArray());
    }

    public function settleAdvance(Request $request, Employee $employee, EmployeeAdvance $advance, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'settled_date' => ['nullable', 'date'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'settlement_amount_minor' => ['nullable', 'integer', 'min:0'],
            'cash_return_minor' => ['nullable', 'integer', 'min:0'],
            'cash_account_id' => ['nullable', 'string'],
            'expense_lines' => ['nullable', 'array'],
            'expense_lines.*.account_id' => ['sometimes', 'required', 'string'],
            'expense_lines.*.amount_minor' => ['sometimes', 'required', 'integer', 'min:1'],
            'expense_lines.*.description' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        return $this->ok($service->settleAdvance($advance, $data, $this->actorId())->toApiArray());
    }

    public function cancelAdvance(Employee $employee, EmployeeAdvance $advance, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        return $this->ok($service->cancelAdvance($advance, $this->actorId())->toApiArray());
    }

    public function issueCustody(Request $request, Employee $employee, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issue_date' => ['nullable', 'date'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'cash_account_id' => ['required', 'string'],
            'item_description' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $data['employee_id'] = $employee->public_id;

        return $this->created($service->issueCustody($data, $this->branchOrFail(), $this->actorId())->toApiArray());
    }

    public function clearCustody(Request $request, Employee $employee, EmployeeCustody $custody, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'cleared_date' => ['nullable', 'date'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'cleared_amount_minor' => ['nullable', 'integer', 'min:0'],
            'cash_account_id' => ['nullable', 'string'],
            'expense_lines' => ['nullable', 'array'],
            'expense_lines.*.account_id' => ['sometimes', 'required', 'string'],
            'expense_lines.*.amount_minor' => ['sometimes', 'required', 'integer', 'min:1'],
            'expense_lines.*.description' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        return $this->ok($service->clearCustody($custody, $data, $this->actorId())->toApiArray());
    }

    public function returnCustody(Request $request, Employee $employee, EmployeeCustody $custody, EmployeeFinanceService $service): JsonResponse
    {
        $this->authorizeBranchId($employee->branch_id);

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'returned_date' => ['nullable', 'date'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'cash_account_id' => ['required', 'string'],
        ]);

        return $this->ok($service->returnCustody($custody, $data, $this->actorId())->toApiArray());
    }

    private function resolveBranch(?string $publicId): Branch
    {
        if ($publicId) {
            $branch = Branch::query()->where('public_id', $publicId)->first();
            if ($branch) {
                return $branch;
            }
        }

        return $this->branchOrFail();
    }
}
