<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatarUrl(),
            'role' => $this->role?->value ?? UserRole::User->value,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'email_notifications' => (bool) $this->email_notifications,
            'push_notifications' => (bool) $this->push_notifications,
            'theme' => $this->theme ?: 'system',
            'created_at' => $this->created_at,
        ];
    }
}