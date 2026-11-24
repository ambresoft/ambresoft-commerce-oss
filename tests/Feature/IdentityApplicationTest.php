<?php

namespace Tests\Feature;

use App\Identity\Application\Command\RegisterUserData;
use App\Identity\Application\Command\RegisterUserHandler;
use App\Identity\Application\Command\ResetPasswordData;
use App\Identity\Application\Command\ResetPasswordHandler;
use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Exception\EmailAlreadyRegistered;
use App\Identity\Domain\Model\User as DomainUser;
use App\Identity\Domain\Model\UserId;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class IdentityApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_use_case_works_without_an_http_request_or_session(): void
    {
        Event::fake([Registered::class]);
        $user = app(RegisterUserHandler::class)->handle(new RegisterUserData('Staff', ' STAFF@example.test ', 'a valid test passphrase'));
        $record = User::findOrFail($user->id->value);

        self::assertSame('staff@example.test', $user->email);
        self::assertTrue(Hash::check('a valid test passphrase', $record->password));
        self::assertFalse($user->emailVerified);
        self::assertFalse(property_exists($user, 'password'));
        Event::assertDispatchedTimes(Registered::class, 1);
        Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($record));
        $this->assertGuest();
    }

    public function test_repository_translates_database_uniqueness_to_a_domain_failure(): void
    {
        Event::fake([Registered::class]);
        $register = app(RegisterUserHandler::class);
        $register->handle(new RegisterUserData('Staff', 'staff@example.test', 'a valid test passphrase'));
        Event::fake([Registered::class]);

        try {
            $register->handle(new RegisterUserData('Duplicate', ' STAFF@example.test ', 'another valid passphrase'));
            self::fail('Duplicate registration must fail.');
        } catch (EmailAlreadyRegistered) {
            $this->assertDatabaseCount('users', 1);
            Event::assertNotDispatched(Registered::class);
        }
    }

    public function test_application_service_uses_its_domain_repository_contract(): void
    {
        Event::fake([Registered::class]);
        $user = new DomainUser(new UserId('1'), 'Staff', 'staff@example.test', false);
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects(self::once())->method('register')
            ->with('Staff', 'staff@example.test', 'a valid test passphrase')->willReturn($user);
        $this->app->instance(UserRepositoryInterface::class, $repository);
        $notifications = $this->createMock(RegistrationNotifierInterface::class);
        $notifications->expects(self::once())->method('registered')->with($user->id);
        $this->app->instance(RegistrationNotifierInterface::class, $notifications);

        self::assertSame($user, app(RegisterUserHandler::class)->handle(new RegisterUserData('Staff', 'staff@example.test', 'a valid test passphrase')));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_reset_use_case_keeps_broker_and_session_revocation_semantics(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'the previous passphrase']);
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        $token = Password::createToken($user);
        $data = new ResetPasswordData($user->email, $token, 'the replacement passphrase');

        self::assertTrue(app(ResetPasswordHandler::class)->handle($data));
        self::assertTrue(Hash::check($data->password, $user->fresh()->password));
        self::assertNotSame('old-remember-token', $user->fresh()->remember_token);
        self::assertFalse(app(ResetPasswordHandler::class)->handle($data));
        Event::assertDispatchedTimes(PasswordReset::class, 1);
        $this->assertGuest();
    }
}
