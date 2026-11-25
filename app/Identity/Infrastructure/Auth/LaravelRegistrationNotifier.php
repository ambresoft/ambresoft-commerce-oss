<?php

namespace App\Identity\Infrastructure\Auth;

use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Model\UserId;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Events\Registered;

final class LaravelRegistrationNotifier implements RegistrationNotifierInterface
{
    public function registered(UserId $id): void
    {
        event(new Registered(UserRecord::findOrFail($id->value)));
    }
}
