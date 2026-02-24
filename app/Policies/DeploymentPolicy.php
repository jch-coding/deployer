<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;

class DeploymentPolicy
{
    /**
     * Create a new policy instance.
     */
    public function put(User $user, Deployment $deployment) : bool
    {
        $deployment_client = $deployment->client;
        return $user->clients()->where('id', $deployment_client->id)->exists();
    }

    public function delete(User $user, Deployment $deployment) : bool
    {
        $deployment_client = $deployment->client;
        return $user->clients()->where('id', $deployment_client->id)->exists();
    }
}
