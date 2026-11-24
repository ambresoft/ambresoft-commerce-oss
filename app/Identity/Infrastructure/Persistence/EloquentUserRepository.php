<?php

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\Exception\EmailAlreadyRegistered;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly UserMapper $mapper) {}

    public function register(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        try {
            // A nested savepoint keeps a handled PostgreSQL uniqueness failure
            // from poisoning a caller's existing transaction.
            $record = DB::transaction(fn (): UserRecord => UserRecord::create(['name' => $name, 'email' => $email, 'password' => $password]));

            return $this->mapper->toDomain($record);
        } catch (UniqueConstraintViolationException) {
            // The only non-primary unique constraint on users is normalized email.
            throw new EmailAlreadyRegistered;
        }
    }
}
