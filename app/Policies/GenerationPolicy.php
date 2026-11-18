<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GenerationPolicy
{
    /**
     * Only the owner may view a generation. Others get a 404, so they cannot tell whether it exists.
     */
    public function view(User $user, Generation $generation): Response
    {
        return $user->id === $generation->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
