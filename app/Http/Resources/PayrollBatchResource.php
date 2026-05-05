<?php

namespace App\Http\Resources;

use App\Models\PayrollBatch;
use App\Models\PayrollItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin PayrollBatch
 */
class PayrollBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();

        return [
            'id' => $this->id,
            'month' => $this->month,
            'year' => $this->year,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'item_count' => $this->when(
                isset($this->items_count) || $this->relationLoaded('items'),
                fn () => $this->items_count ?? $items->count(),
            ),
            'totals' => $this->when(
                isset($this->total_gross_salary) || $this->relationLoaded('items'),
                fn () => [
                    'gross_salary' => $this->decimalSummary('total_gross_salary', $items, 'gross_salary'),
                    'unpaid_deduction' => $this->decimalSummary('total_unpaid_deduction', $items, 'unpaid_deduction'),
                    'absence_deduction' => $this->decimalSummary('total_absence_deduction', $items, 'absence_deduction'),
                    'tax_amount' => $this->decimalSummary('total_tax_amount', $items, 'tax_amount'),
                    'nssf_deduction' => $this->decimalSummary('total_nssf_deduction', $items, 'nssf_deduction'),
                    'net_salary' => $this->decimalSummary('total_net_salary', $items, 'net_salary'),
                ],
            ),
            'generated_at' => $this->generated_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'generated_by_user' => $this->whenLoaded('generatedBy', fn () => $this->userPayload($this->generatedBy)),
            'submitted_by_user' => $this->whenLoaded('submittedBy', fn () => $this->userPayload($this->submittedBy)),
            'approved_by_user' => $this->whenLoaded('approvedBy', fn () => $this->userPayload($this->approvedBy)),
            'rejected_by_user' => $this->whenLoaded('rejectedBy', fn () => $this->userPayload($this->rejectedBy)),
            'items' => $this->whenLoaded(
                'items',
                fn () => PayrollItemResource::collection($this->items)->resolve($request),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, PayrollItem>  $items
     */
    protected function decimalSummary(string $attribute, Collection $items, string $itemField): string
    {
        if (isset($this->{$attribute})) {
            return number_format((float) $this->{$attribute}, 2, '.', '');
        }

        return number_format((float) $items->sum(fn (PayrollItem $item) => (float) $item->{$itemField}), 2, '.', '');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userPayload(mixed $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
