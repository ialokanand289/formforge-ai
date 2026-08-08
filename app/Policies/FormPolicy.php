<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function update(User $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    protected function owns(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }
}
