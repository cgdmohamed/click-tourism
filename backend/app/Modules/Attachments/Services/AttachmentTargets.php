<?php

namespace App\Modules\Attachments\Services;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Which records may carry documents, and who may see them: a document is reachable only by a user
 * who may view its record (permission and branch) and holds the matching attachments permission;
 * sensitive categories (passports, visas, travel documents) also need attachments.sensitive.
 */
class AttachmentTargets
{
    /** type => [model, permission that lets a user see the record] */
    private const TARGETS = [
        'customer' => [Customer::class, 'customers.view'],
        'supplier' => [Supplier::class, 'suppliers.view'],
        'booking' => [Booking::class, 'bookings.view'],
        'invoice' => [Invoice::class, 'invoices.view'],
        'payment' => [Payment::class, 'payments.view'],
        'expense' => [Expense::class, 'expenses.view'],
    ];

    public function __construct(private readonly BranchScope $branches) {}

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TARGETS);
    }

    public function find(string $type, string $publicId): Model
    {
        if (! isset(self::TARGETS[$type])) {
            throw new ModelNotFoundException('Unknown record type.');
        }

        return self::TARGETS[$type][0]::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** The record an attachment belongs to (null when it has been removed). */
    public function parentOf(Attachment $attachment): ?Model
    {
        $class = self::TARGETS[$attachment->attachable_type][0] ?? null;

        return $class ? $class::query()->find($attachment->attachable_id) : null;
    }

    /** @param  'view'|'upload'|'delete'|'extract'  $ability */
    public function authorizeRecord(User $user, string $type, Model $record, string $ability): void
    {
        $this->authorizeParts($user, $type, $record->getAttribute('branch_id'), $ability, null);
    }

    /** @param  'view'|'upload'|'delete'|'extract'  $ability */
    public function authorizeAttachment(User $user, Attachment $attachment, string $ability): void
    {
        $this->authorizeParts($user, $attachment->attachable_type, $attachment->branch_id, $ability, $attachment);
    }

    /** True when the user may open documents of sensitive categories. */
    public function canSeeSensitive(User $user): bool
    {
        return $user->can('attachments.sensitive');
    }

    private function authorizeParts(User $user, string $type, ?int $branchId, string $ability, ?Attachment $attachment): void
    {
        $viewPermission = self::TARGETS[$type][1] ?? null;
        if ($viewPermission === null
            || ! $user->can($viewPermission)
            || ! $user->can('attachments.'.$ability)) {
            throw new AuthorizationException('You are not allowed to do this with documents of this record.');
        }
        if ($branchId !== null && ! in_array($branchId, $this->branches->allowedBranchIds($user), true)) {
            throw new AuthorizationException('You are not assigned to this record\'s branch.');
        }
        if ($attachment !== null && $attachment->isSensitive() && ! $this->canSeeSensitive($user)) {
            throw new AuthorizationException('This document contains personal data that your role may not open.');
        }
    }
}
