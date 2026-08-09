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

    /**
     * Any signed-in user may create their own form.
     *
     * Ownership is not a question here because there is no subject yet: the
     * creator becomes the owner, and user_id is taken from the session rather
     * than the payload.
     */
    public function create(User $user): bool
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

    /**
     * Replace the working schema with an older snapshot.
     *
     * Separated from update because it is the only action that discards the
     * current schema wholesale, so it should be auditable and tightenable on
     * its own. Deliberately not named restore: Form uses SoftDeletes, and
     * Laravel reserves that ability for undeleting a trashed model.
     */
    public function rollback(User $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    /**
     * Make the form reachable by anyone holding its public token, or withdraw it.
     *
     * Its own ability because this is the single point where a private form
     * becomes world-readable; an auditor should be able to find that gate by
     * name rather than infer it from update.
     */
    public function publish(User $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    protected function owns(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }
}
