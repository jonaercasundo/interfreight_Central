<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create') && $actor->can('users.assign-role');
    }

    public function update(User $actor, User $subject): bool
    {
        return $actor->can('users.update');
    }

    public function assignRoles(User $actor, User $subject): bool
    {
        return $actor->can('users.assign-role');
    }

    public function deactivate(User $actor, User $subject): bool
    {
        return $actor->can('users.deactivate');
    }

    public function resetPassword(User $actor, User $subject): bool
    {
        return $actor->id !== $subject->id
            && $actor->can('users.password.reset')
            && (! $subject->hasRole('Administrator') || $actor->can('users.password.reset.privileged'));
    }
}
