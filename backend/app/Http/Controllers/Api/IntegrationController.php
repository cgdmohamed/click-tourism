<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Exceptions\OutboundRefusedException;
use App\Modules\Integrations\Models\WhatsAppMessage;
use App\Modules\Integrations\Services\ConsentService;
use App\Modules\Integrations\Services\IntegrationSettings;
use App\Modules\Integrations\Services\PhoneNumber;
use App\Modules\Integrations\Services\WhatsAppOutboundService;
use App\Modules\Parties\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin screen + staff actions for the optional Meta / WhatsApp integrations.
 * Secrets are never accepted or returned: the status only exposes
 * `configured` booleans for env-provided values.
 */
class IntegrationController extends ApiController
{
    public function status(IntegrationSettings $settings): JsonResponse
    {
        return $this->ok($settings->status());
    }

    public function updateSettings(Request $request, SettingService $store, IntegrationSettings $settings, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'meta_leads_enabled' => ['sometimes', 'boolean'],
            'meta_page_id' => ['sometimes', 'nullable', 'regex:/^\d{0,40}$/'],
            'meta_default_branch_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'meta_form_branch_map' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'meta_graph_version' => ['sometimes', 'nullable', 'regex:/^v\d{1,2}\.\d{1,2}$/'],
            'whatsapp_enabled' => ['sometimes', 'boolean'],
            'whatsapp_outbound_enabled' => ['sometimes', 'boolean'],
            'whatsapp_phone_number_id' => ['sometimes', 'nullable', 'regex:/^\d{0,40}$/'],
            'whatsapp_business_account_id' => ['sometimes', 'nullable', 'regex:/^\d{0,40}$/'],
            'whatsapp_graph_version' => ['sometimes', 'nullable', 'regex:/^v\d{1,2}\.\d{1,2}$/'],
            'whatsapp_stop_keywords' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $this->validateBranchReferences($data);

        $before = $settings->status();

        foreach ($data as $key => $value) {
            if (! in_array($key, IntegrationSettings::WRITABLE, true)) {
                continue;
            }
            $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
            $store->set(IntegrationSettings::GROUP, $key, $stored, false);
        }

        $after = $settings->status();
        $audit->log('integrations.settings_changed', null, $this->auditView($before), $this->auditView($after), $this->actorId());

        return $this->ok($after);
    }

    public function messages(Request $request): JsonResponse
    {
        $request->validate([
            'lead_id' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'string', 'max:40'],
        ]);

        $query = WhatsAppMessage::query()->with(['lead', 'customer'])->orderByDesc('id');

        if ($request->query('lead_id')) {
            $lead = Lead::query()->where('public_id', $request->query('lead_id'))->firstOrFail();
            $this->authorizeBranchId($lead->branch_id);
            $query->where('lead_id', $lead->id);
        } elseif ($request->query('customer_id')) {
            $customer = Customer::query()->where('public_id', $request->query('customer_id'))->firstOrFail();
            $this->authorizeBranchId($customer->branch_id);
            $query->where('customer_id', $customer->id);
        } else {
            // Unfiltered listing is limited to the user's branch.
            $branch = $this->branch();
            $query->when($branch, fn ($q) => $q->where(function ($q) use ($branch) {
                $q->whereHas('lead', fn ($l) => $l->where('branch_id', $branch->id))
                    ->orWhereHas('customer', fn ($c) => $c->where('branch_id', $branch->id));
            }));
        }

        $page = $query->paginate(min(100, max(1, (int) $request->query('per_page', 50))));
        $page->getCollection()->transform(fn (WhatsAppMessage $message) => $message->toApiArray());

        return $this->paginated($page);
    }

    public function send(Request $request, WhatsAppOutboundService $outbound): JsonResponse
    {
        $data = $request->validate([
            'lead_id' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
            'customer_id' => ['required_without:lead_id', 'nullable', 'string', 'max:40'],
            'type' => ['required', 'in:text,template'],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4000'],
            'template_name' => ['required_if:type,template', 'nullable', 'regex:/^[a-z0-9_]{1,512}$/'],
            'language' => ['nullable', 'regex:/^[a-z]{2,3}(_[A-Za-z]{2})?$/'],
            'components' => ['nullable', 'array', 'max:10'],
        ]);

        [$lead, $customer] = $this->resolveTarget($data);

        try {
            $message = $outbound->enqueue(
                $lead,
                $customer,
                $data,
                $this->idempotencyKey($request),
                $this->actorId(),
            );
        } catch (OutboundRefusedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->reason], 422);
        }

        return $this->ok($message->load(['lead', 'customer'])->toApiArray(), 202);
    }

    public function recordConsent(Request $request, ConsentService $consent, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'lead_id' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
            'customer_id' => ['required_without:lead_id', 'nullable', 'string', 'max:40'],
            'status' => ['required', 'in:opt_in,opt_out'],
            'source' => ['required', 'in:lead_form,in_person,phone_call,written,website,manual'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$lead, $customer] = $this->resolveTarget($data);
        $phone = PhoneNumber::digits($lead?->phone ?? $customer?->phone);
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw ValidationException::withMessages(['lead_id' => ['The contact has no valid phone number.']]);
        }

        $record = $consent->record($phone, $data['status'], $data['source'], $lead?->id, $customer?->id, $this->actorId(), $data['note'] ?? null);
        $audit->log('integrations.whatsapp_consent_'.$data['status'], null, null, [
            'phone' => PhoneNumber::mask($phone),
            'source' => $data['source'],
            'lead_id' => $lead?->public_id,
            'customer_id' => $customer?->public_id,
        ], $this->actorId());

        return $this->created([
            'status' => $record->status,
            'source' => $record->source,
            'recorded_at' => $record->recorded_at->toIso8601String(),
        ]);
    }

    /** @return array{0: ?Lead, 1: ?Customer} */
    private function resolveTarget(array $data): array
    {
        $lead = null;
        $customer = null;
        if (! empty($data['lead_id'])) {
            $lead = Lead::query()->where('public_id', $data['lead_id'])->firstOrFail();
            $this->authorizeBranchId($lead->branch_id);
        }
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->where('public_id', $data['customer_id'])->firstOrFail();
            $this->authorizeBranchId($customer->branch_id);
        }

        return [$lead, $customer];
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key !== '' ? 'wa:'.$this->actorId().':'.mb_substr($key, 0, 100) : null;
    }

    private function validateBranchReferences(array $data): void
    {
        $errors = [];
        if (! empty($data['meta_default_branch_id']) && ! Branch::query()->where('public_id', $data['meta_default_branch_id'])->exists()) {
            $errors['meta_default_branch_id'] = ['Unknown branch.'];
        }
        if (! empty($data['meta_form_branch_map'])) {
            $map = json_decode($data['meta_form_branch_map'], true);
            if (! is_array($map)) {
                $errors['meta_form_branch_map'] = ['Must be a JSON object of form id to branch id.'];
            } else {
                foreach ($map as $formId => $branchId) {
                    if (! is_string($branchId) || ! preg_match('/^\d{1,40}$/', (string) $formId) || ! Branch::query()->where('public_id', $branchId)->exists()) {
                        $errors['meta_form_branch_map'] = ['Every entry must map a numeric form id to a known branch id.'];
                        break;
                    }
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Settings-only view for the audit trail (no counters or timestamps). */
    private function auditView(array $status): array
    {
        $strip = fn (array $section) => array_diff_key($section, array_flip([
            'configured', 'webhook_url', 'webhook_verified_at', 'last_event_at', 'failed_events', 'queued_messages', 'failed_messages',
        ]));

        return ['meta' => $strip($status['meta']), 'whatsapp' => $strip($status['whatsapp'])];
    }
}
