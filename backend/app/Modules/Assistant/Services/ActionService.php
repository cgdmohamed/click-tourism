<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assistant\Actions\ActionCatalogue;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\Redactor;
use App\Modules\Assistant\Support\SchemaValidator;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Treasury\Models\CashAccount;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepare, confirm and reject assistant actions (ADR-0023).
 *
 * Preparing stores what the model asked for and shows it to the user; it changes nothing else.
 * Confirming replays the same request through the normal API route AS THE USER (their
 * permissions, branch scope, validation, idempotency and audit all apply), then marks the new
 * record `created_via = assistant`. The model never confirms: only the user's own request can.
 */
class ActionService
{
    public function __construct(
        private readonly ActionCatalogue $catalogue,
        private readonly SchemaValidator $validator,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, text: string, card: ?array<string, mixed>}
     */
    public function propose(User $user, ?Branch $branch, string $tool, array $args, Redactor $redactor, string $locale): array
    {
        $definition = $this->catalogue->find($tool);
        if ($definition === null || ! $user->can($definition['permission'])) {
            return $this->refuse('forbidden', 'This action is not available to the user\'s role.');
        }
        if ($branch === null) {
            return $this->refuse('no_branch', 'An active branch is required to prepare this action.');
        }

        array_walk_recursive($args, function (&$value) use ($redactor) {
            if (is_string($value)) {
                $value = $redactor->restore($value);
            }
        });
        $problems = $this->validator->errors($definition['schema'], $args);
        if ($problems !== []) {
            return $this->refuse('validation_failed', 'Invalid arguments: '.implode('; ', array_slice($problems, 0, 5)));
        }

        try {
            $payload = $this->prepare($tool, $args, $branch);
        } catch (\DomainException $e) {
            return $this->refuse('validation_failed', $e->getMessage());
        }

        $action = AssistantAction::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'tool' => $tool,
            'payload' => $payload,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(AssistantAction::TTL_MINUTES),
        ]);
        $this->audit->log('assistant.action_proposed', null, null, ['tool' => $tool, 'action' => $action->public_id], $user->id, $branch->id);

        return [
            'ok' => true,
            'text' => "<erp_action_proposed id=\"{$action->public_id}\">\nThe action is shown to the user as a confirmation card. It has NOT been executed and nothing was saved. Tell the user briefly what you prepared and ask them to review and press Confirm on the card. Never say it is done.\n</erp_action_proposed>",
            'card' => $this->card($action, $locale),
        ];
    }

    /** @return array<string, mixed> */
    public function confirm(User $user, ?Branch $branch, string $publicId, string $locale): array
    {
        return DB::transaction(function () use ($user, $branch, $publicId, $locale) {
            $action = AssistantAction::query()->where('user_id', $user->id)->where('public_id', $publicId)->lockForUpdate()->first();
            if ($action === null) {
                throw new ActionException('Action not found.', 'not_found', 404);
            }
            if ($action->status !== 'pending') {
                throw new ActionException('This action was already decided.', 'assistant_action_decided', 409);
            }
            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired', 'decided_at' => now()]);
                throw new ActionException('This action expired. Ask the assistant to prepare it again.', 'assistant_action_expired', 410);
            }
            if ($action->branch_id !== $branch?->id) {
                throw new ActionException('This action was prepared in another branch.', 'assistant_action_branch_mismatch', 409);
            }

            $definition = $this->catalogue->find($action->tool);
            $payload = $action->payload;
            $path = str_replace('{id}', rawurlencode((string) ($payload['__id'] ?? '')), $definition['route']);
            $body = $this->withoutDisplayKeys($payload);

            $response = $this->dispatch($user, $definition['method'], $path, $body, $branch->public_id, $locale, 'assistant-'.$action->public_id);
            $json = json_decode((string) $response->getContent(), true);
            $json = is_array($json) ? $json : [];

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $entityId = (string) ($json['data']['id'] ?? ($payload['__id'] ?? ''));
                if ($definition['marks'] && $entityId !== '') {
                    $definition['model']::query()->where('public_id', $entityId)->update(['created_via' => 'assistant']);
                }
                $action->update(['status' => 'confirmed', 'entity' => $definition['entity'], 'entity_id' => $entityId ?: null, 'decided_at' => now()]);
                $this->audit->log('assistant.action_executed', null, null, ['tool' => $action->tool, 'action' => $action->public_id, 'entity' => $definition['entity'], 'id' => $entityId], $user->id, $branch->id);
            } else {
                $message = (string) ($json['message'] ?? 'The request could not be completed.');
                $status = $response->getStatusCode();
                $code = $status === 403 ? 'forbidden' : ($status === 422 ? 'validation_failed' : 'failed');
                if ($status >= 500) {
                    $message = 'The request could not be completed. Nothing was saved.';
                }
                $action->update(['status' => 'failed', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 500), 'decided_at' => now()]);
                $this->audit->log('assistant.action_failed', null, null, ['tool' => $action->tool, 'action' => $action->public_id, 'status' => $status], $user->id, $branch->id);
            }

            return $this->card($action->refresh(), $locale);
        });
    }

    /** @return array<string, mixed> */
    public function reject(User $user, string $publicId, string $locale): array
    {
        $action = AssistantAction::query()->where('user_id', $user->id)->where('public_id', $publicId)->first();
        if ($action === null) {
            throw new ActionException('Action not found.', 'not_found', 404);
        }
        if ($action->status === 'pending') {
            $action->update(['status' => 'rejected', 'decided_at' => now()]);
            $this->audit->log('assistant.action_rejected', null, null, ['tool' => $action->tool, 'action' => $action->public_id], $user->id, $action->branch_id);
        }

        return $this->card($action->refresh(), $locale);
    }

    /**
     * Current cards of the user's own actions (saved chats show the real status).
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function cards(User $user, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $actions = AssistantAction::query()->where('user_id', $user->id)->whereIn('public_id', $ids)->get()->keyBy('public_id');

        return collect($ids)->map(fn ($id) => $actions->get($id))->filter()->map(fn (AssistantAction $a) => $this->card($a, $locale))->values()->all();
    }

    /** @return array<string, mixed> */
    public function card(AssistantAction $action, string $locale): array
    {
        $status = $action->status === 'pending' && $action->expires_at->isPast() ? 'expired' : $action->status;
        $payload = $action->payload;

        return [
            'id' => $action->public_id,
            'tool' => $action->tool,
            'title' => ActionLabels::title($action->tool, $locale),
            'status' => $status,
            'risk' => $this->catalogue->find($action->tool)['risk'] ?? 'create',
            'rows' => ActionLabels::rows($payload, $locale),
            'expires_at' => $action->expires_at->toIso8601String(),
            'result' => $action->entity_id !== null ? ['entity' => $action->entity, 'id' => $action->entity_id] : null,
            'error' => $action->status === 'failed' ? ['code' => $action->error_code, 'message' => $action->error_message] : null,
        ];
    }

    /**
     * The request body (and display rows) for the route, with everything the model must not decide
     * (branch, draft state, accounts) fixed here.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function prepare(string $tool, array $args, Branch $branch): array
    {
        $base = $this->settings->baseCurrency();

        return match ($tool) {
            'erp_lead_create' => $args + ['branch_id' => $branch->public_id],
            'erp_lead_update' => $this->prepareLeadUpdate($args, $branch),
            'erp_customer_create' => $args + ['branch_id' => $branch->public_id],
            'erp_supplier_create' => $args + ['branch_id' => $branch->public_id],
            'erp_invoice_draft_create' => $this->prepareInvoice($args, $branch, $base),
            'erp_expense_draft_create' => $this->prepareExpense($args, $branch, $base),
            'erp_booking_draft_create' => $this->prepareBooking($args, $branch),
            'erp_payment_record' => $this->preparePayment($args, $branch, $base),
            'erp_supplier_payment_record' => $this->prepareSupplierPayment($args, $branch, $base),
            'erp_export_queue' => $this->prepareExport($args),
            default => ($this->catalogue->find($tool)['kind'] ?? null) === 'record'
                ? $this->prepareRecord($tool, $args, $branch)
                : throw new \DomainException('Unknown action.'),
        };
    }

    private function prepareLeadUpdate(array $args, Branch $branch): array
    {
        $lead = Lead::query()->where('public_id', $args['id'])->where('branch_id', $branch->id)->first();
        if ($lead === null) {
            throw new \DomainException('Lead not found in this branch.');
        }
        if (! isset($args['stage']) && ! isset($args['note'])) {
            throw new \DomainException('Give a stage or a note to change.');
        }

        return array_filter(['stage' => $args['stage'] ?? null, 'note' => $args['note'] ?? null]) + ['__id' => $lead->public_id, '__lead' => $lead->name];
    }

    private function prepareInvoice(array $args, Branch $branch, string $base): array
    {
        $customer = Customer::query()->where('public_id', $args['customer_id'])->first();
        if ($customer === null) {
            throw new \DomainException('Customer not found.');
        }
        $account = (string) $this->settings->get('accounting', 'revenue_account_code', '4100');
        $booking = null;
        if (! empty($args['booking_id'])) {
            $booking = Booking::query()->where('public_id', $args['booking_id'])->first();
            if ($booking === null) {
                throw new \DomainException('Booking not found.');
            }
        }

        return [
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'booking_id' => $args['booking_id'] ?? null,
            'issue_date' => $args['issue_date'],
            'due_date' => $args['due_date'] ?? null,
            'currency' => strtoupper($args['currency'] ?? $base),
            'memo' => $args['memo'] ?? null,
            'lines' => array_map(fn (array $line) => [
                'account_id' => $account, 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor'],
                'credit_minor' => $line['quantity'] * $line['unit_price_minor'],
            ], $args['lines']),
            'submit' => false,
            '__customer' => $customer->name_en ?: $customer->name_ar,
            '__booking' => $booking?->number,
        ];
    }

    private function prepareExpense(array $args, Branch $branch, string $base): array
    {
        $needle = mb_strtolower(trim($args['category']));
        $categories = ExpenseCategory::query()->where('is_active', true)->get();
        $matches = $categories->filter(fn ($c) => mb_strtolower($c->code) === $needle || mb_strtolower((string) $c->name_en) === $needle || mb_strtolower((string) $c->name_ar) === $needle);
        if ($matches->count() !== 1) {
            $list = $categories->take(25)->map(fn ($c) => "{$c->code} ({$c->name_en})")->implode(', ');
            throw new \DomainException($matches->isEmpty()
                ? "Expense category not found. Available: {$list}."
                : 'More than one category matches. Use the code. Available: '.$list.'.');
        }
        $category = $matches->first();
        unset($args['category']);

        $paying = [];
        if (isset($args['supplier_id'])) {
            $supplier = Supplier::query()->where('public_id', $args['supplier_id'])->first();
            if ($supplier === null) {
                throw new \DomainException('Supplier not found.');
            }
            unset($args['supplier_id']);
            $paying = ['payee_type' => 'supplier', 'payee_id' => $supplier->public_id, '__payee' => $supplier->name_en ?: $supplier->name_ar];
        } else {
            $accounts = CashAccount::query()->where('is_active', true)->where('branch_id', $branch->id)->get();
            $needle = mb_strtolower(trim((string) ($args['payment_account'] ?? '')));
            $match = $accounts->filter(fn ($a) => $needle !== '' && (mb_strtolower((string) $a->name_en) === $needle || mb_strtolower((string) $a->name_ar) === $needle));
            if ($match->count() !== 1) {
                $list = $accounts->take(15)->map(fn ($a) => $a->name_en ?: $a->name_ar)->implode(', ');
                throw new \DomainException('Say which payment account pays (payment_account) or give a supplier_id. Payment accounts in this branch: '.($list ?: 'none').'.');
            }
            $paying = ['cash_account_id' => $match->first()->public_id, '__payment' => $match->first()->name_en ?: $match->first()->name_ar];
        }
        unset($args['payment_account']);

        return $args + $paying + ['category_id' => $category->public_id, 'currency' => strtoupper($args['currency'] ?? $base), 'submit' => false, '__category' => $category->name_en ?: $category->code];
    }

    private function prepareBooking(array $args, Branch $branch): array
    {
        $customer = Customer::query()->where('public_id', $args['customer_id'])->first();
        if ($customer === null) {
            throw new \DomainException('Customer not found.');
        }

        // Every supplier named on an item must exist: the card then shows who it is, not an id.
        $args['items'] = array_map(function (array $item) {
            if (! empty($item['supplier_id'])) {
                $supplier = Supplier::query()->where('public_id', $item['supplier_id'])->first();
                if ($supplier === null) {
                    throw new \DomainException("Supplier {$item['supplier_id']} not found.");
                }
                $item['__supplier'] = $supplier->name_en ?: $supplier->name_ar;
            }

            return $item;
        }, $args['items']);

        return ['branch_id' => $branch->public_id, 'currency' => strtoupper($args['currency'])] + $args + ['__customer' => $customer->name_en ?: $customer->name_ar];
    }

    /**
     * An action on an existing record: it is found in THIS branch by public id or number, and the card
     * names it with its current status so the user sees exactly what they are about to change.
     */
    private function prepareRecord(string $tool, array $args, Branch $branch): array
    {
        $definition = $this->catalogue->find($tool);
        $query = $definition['model']::query();
        if (! empty($args['id'])) {
            $query->where('public_id', $args['id']);
        } elseif (! empty($args['number'])) {
            $query->where('number', $args['number']);
        } else {
            throw new \DomainException('Give the record id or its number.');
        }
        $records = $query->where('branch_id', $branch->id)->limit(2)->get();
        if ($records->count() !== 1) {
            throw new \DomainException($records->isEmpty() ? 'No such record in this branch.' : 'More than one record has this number: use the id.');
        }
        $record = $records->first();

        $payload = ['__tool' => $tool, '__id' => $record->public_id, '__record' => (string) ($record->number ?? $record->public_id), '__status' => (string) $record->status];
        // The amount the user is asked to approve: a journal entry has none stored, it is the sum of its debit lines.
        if ($record instanceof JournalEntry) {
            $record->loadMissing('lines');
            $mixed = $record->lines->pluck('currency_code')->unique()->count() > 1;
            $minor = $record->totalMinor();
            $currency = $mixed ? $this->settings->baseCurrency() : (string) ($record->currency_code ?: $this->settings->baseCurrency());
        } else {
            $minor = $record->total_minor ?? $record->amount_minor ?? null;
            $currency = (string) ($record->currency_code ?? '');
        }
        if ($minor !== null) {
            $payload['__total'] = ActionLabels::moneyText((int) $minor, $currency);
        }
        $note = $record->memo ?? $record->description ?? null;
        if (! empty($note)) {
            $payload['__note'] = mb_substr((string) $note, 0, 120);
        }

        if ($tool === 'erp_commission_pay') {
            // The route pays what is outstanding when no amount is sent; state it and send it, so
            // confirming authorises exactly the amount the card shows.
            $outstanding = (int) $record->amount_minor - (int) $record->paid_minor;
            if ($outstanding <= 0) {
                throw new \DomainException('Nothing is outstanding on this commission.');
            }
            $account = $this->cashAccount((string) ($args['payment_account'] ?? ''), $branch);
            $payload += [
                'cash_account_id' => $account->public_id, '__payment' => $account->name_en ?: $account->name_ar,
                'amount_minor' => $outstanding, 'currency' => (string) $record->currency_code,
            ];
            if ((int) $record->paid_minor > 0) {
                $payload['__paid'] = ActionLabels::moneyText((int) $record->paid_minor, (string) $record->currency_code);
            }
            if (! empty($args['reference'])) {
                $payload['reference'] = $args['reference'];
            }
        }

        return $payload;
    }

    private function preparePayment(array $args, Branch $branch, string $base): array
    {
        $customer = Customer::query()->where('public_id', $args['customer_id'])->first();
        if ($customer === null) {
            throw new \DomainException('Customer not found.');
        }
        $account = $this->cashAccount($args['payment_account'], $branch);
        $currency = strtoupper($args['currency'] ?? $base);

        // With a booking and no explicit allocations the route puts the WHOLE receipt on that booking,
        // so the card must name it; without one the receipt is an unallocated advance.
        $booking = null;
        if (! empty($args['booking_id'])) {
            $booking = Booking::query()->where('public_id', $args['booking_id'])->orWhere('number', $args['booking_id'])->first();
            if ($booking === null || $booking->branch_id !== $branch->id) {
                throw new \DomainException('Booking not found in this branch.');
            }
            if ($booking->customer_id !== $customer->id) {
                throw new \DomainException('That booking belongs to another customer.');
            }
        }

        return array_filter([
            '__tool' => 'erp_payment_record',
            '__allocation' => $booking !== null ? 'booking' : 'advance',
            '__booking' => $booking?->number,
            'customer_id' => $customer->public_id, 'branch_id' => $branch->public_id, 'cash_account_id' => $account->account->public_id,
            'method' => $args['method'] ?? 'cash', 'amount_minor' => $args['amount_minor'], 'currency' => $currency,
            'booking_id' => $booking?->public_id, 'paid_at' => $args['paid_at'] ?? null,
            '__customer' => $customer->name_en ?: $customer->name_ar, '__payment' => $account->name_en ?: $account->name_ar,
        ], fn ($v) => $v !== null);
    }

    private function prepareSupplierPayment(array $args, Branch $branch, string $base): array
    {
        $supplier = Supplier::query()->where('public_id', $args['supplier_id'])->first();
        if ($supplier === null) {
            throw new \DomainException('Supplier not found.');
        }
        $account = $this->cashAccount($args['payment_account'], $branch);

        return array_filter([
            '__tool' => 'erp_supplier_payment_record',
            'supplier_id' => $supplier->public_id, 'branch_id' => $branch->public_id, 'cash_account_id' => $account->account->public_id,
            'amount_minor' => $args['amount_minor'], 'currency' => strtoupper($args['currency'] ?? $base), 'paid_at' => $args['paid_at'] ?? null,
            '__payee' => $supplier->name_en ?: $supplier->name_ar, '__payment' => $account->name_en ?: $account->name_ar,
        ], fn ($v) => $v !== null);
    }

    private function prepareExport(array $args): array
    {
        return array_filter([
            '__tool' => 'erp_export_queue',
            'report' => $args['report'], 'format' => $args['format'], 'language' => $args['language'] ?? null,
            'params' => $args['params'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** The active cash or bank account of this branch with exactly this name; otherwise the user is told which exist. */
    private function cashAccount(string $name, Branch $branch): CashAccount
    {
        $accounts = CashAccount::query()->where('is_active', true)->where('branch_id', $branch->id)->get();
        $needle = mb_strtolower(trim($name));
        $match = $accounts->filter(fn ($a) => $needle !== '' && (mb_strtolower((string) $a->name_en) === $needle || mb_strtolower((string) $a->name_ar) === $needle));
        if ($match->count() !== 1) {
            $list = $accounts->take(15)->map(fn ($a) => $a->name_en ?: $a->name_ar)->implode(', ');
            throw new \DomainException('Say which payment account to use (payment_account). Payment accounts in this branch: '.($list ?: 'none').'.');
        }

        return $match->first();
    }

    /** Display-only keys (`__...`) never reach the route, at any depth. */
    private function withoutDisplayKeys(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '__')) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->withoutDisplayKeys($value) : $value;
        }

        return $clean;
    }

    /** Same-process request to the normal API route, as the user. */
    private function dispatch(User $user, string $method, string $path, array $body, string $branchPublicId, string $locale, string $idempotencyKey): Response
    {
        $request = Request::create('/api/v1'.$path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));
        $request->headers->set('Accept-Language', $locale);
        $request->headers->set('X-Branch-Id', $branchPublicId);
        $request->headers->set('Idempotency-Key', $idempotencyKey);
        $request->setUserResolver(fn () => $user);
        Auth::guard('sanctum')->setUser($user);

        $original = app('request');
        app()->instance('request', $request);

        try {
            return app('router')->dispatch($request);
        } catch (\Throwable $e) {
            $handler = app(ExceptionHandler::class);
            $handler->report($e);

            return $handler->render($request, $e);
        } finally {
            app()->instance('request', $original);
        }
    }

    /** @return array{ok: false, text: string, card: null} */
    private function refuse(string $code, string $message): array
    {
        return ['ok' => false, 'text' => "<erp_error>\n".json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE)."\n</erp_error>", 'card' => null];
    }
}
