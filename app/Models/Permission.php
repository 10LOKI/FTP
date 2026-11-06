<?php

namespace App\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['code', 'module', 'label'];

    protected $hidden = ['pivot'];

    protected static function booted(): void
    {
        static::updating(function (Permission $permission): void {
            if ($permission->isDirty('code')) {
                throw new \LogicException('Permission codes cannot be renamed.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_protected' => 'boolean'];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
