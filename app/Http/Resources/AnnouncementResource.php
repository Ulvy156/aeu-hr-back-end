<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Models\AnnouncementTarget;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'title' => $this->title,
            'content' => $this->content,
            'priority' => $this->priority,
            'status' => $this->status,
            'attachment' => $this->attachment_path ? [
                'name' => $this->attachment_name,
                'size' => $this->attachment_size,
                'url' => FileStorage::url($this->attachment_path),
            ] : null,
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(fn (AnnouncementTarget $target) => [
                'target_type' => $target->target_type,
                'target_id' => $target->target_id,
            ])),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'submitted_by_user' => $this->whenLoaded('submitter', fn () => $this->submitter ? [
                'id' => $this->submitter->id,
                'name' => $this->submitter->name,
            ] : null),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_by_user' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_by_user' => $this->whenLoaded('rejector', fn () => $this->rejector ? [
                'id' => $this->rejector->id,
                'name' => $this->rejector->name,
            ] : null),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'is_read' => $this->whenLoaded('views', fn () => $this->views->isNotEmpty()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
