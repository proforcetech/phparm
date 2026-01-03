<?php

namespace App\Support\Auth;

use App\Models\User;

class AccessMiddleware
{
    private AccessGate $gate;

    public function __construct(AccessGate $gate)
    {
        $this->gate = $gate;
    }

    /**
     * @param callable $next
     * @return mixed
     */
    public function handle(User $user, string $permission, callable $next)
    {
        $this->gate->assert($user, $permission);

        return $next();
    }
}
