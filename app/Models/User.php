<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Status;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    use HasRoles {
        hasPermissionTo as private hasAssignedPermissionTo;
        getAllPermissions as private getAssignedPermissions;
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->hasAssignedPermissionTo($permission, $guardName)) {
            return true;
        }
        $permission = $this->filterPermission($permission, $guardName);

        return $permission->guard_name === $this->getDefaultGuardName()
            && in_array($permission->name, $this->announcementPermissionNames(), true)
            && $this->hasAnnouncementJobLevel();
    }

    public function getAllPermissions(): Collection
    {
        $permissions = $this->getAssignedPermissions();
        if ($this->hasAnnouncementJobLevel()) {
            $permissions = $permissions->merge($this->getPermissionClass()::query()
                ->where('guard_name', $this->getDefaultGuardName())
                ->whereIn('name', $this->announcementPermissionNames())->get());
        }

        return $permissions->unique('id')->values();
    }

    /** Query the current position so promotions and position edits take effect immediately. */
    private function hasAnnouncementJobLevel(): bool
    {
        return Position::query()->whereIn('job_level', config('hr_permissions.announcement_job_levels', []))
            ->whereHas('employees', fn (Builder $query) => $query->where('user_id', $this->getKey()))->exists();
    }

    /** @return array<int, string> */
    private function announcementPermissionNames(): array
    {
        return [...array_keys(config('hr_permissions.groups.announcements', [])), 'announcement_categories.view'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => Status::class,
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function employeeWithTrashed(): HasOne
    {
        return $this->hasOne(Employee::class)->withTrashed();
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function announcement()
    {
        return $this->hasMany(Announcement::class);
    }
}
