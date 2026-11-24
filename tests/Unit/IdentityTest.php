<?php

namespace Tests\Unit;

use App\Identity\Application\Command\RegisterUserData;
use App\Identity\Application\Command\RegisterUserHandler;
use App\Identity\Application\Command\ResetPasswordData;
use App\Identity\Application\Command\ResetPasswordHandler;
use App\Identity\Application\Contract\PasswordResetterInterface;
use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Exception\EmailAlreadyRegistered;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Model\UserId;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function test_identity_keeps_large_ids_exact_and_excludes_authentication_secrets(): void
    {
        $user = new User(new UserId('9007199254740993'), 'Staff', 'staff@example.test', false);
        self::assertSame('9007199254740993', $user->id->value);
        self::assertFalse($user->emailVerified);
        self::assertArrayNotHasKey('password', get_object_vars($user));
        self::assertArrayNotHasKey('remember_token', get_object_vars($user));
    }

    public function test_identity_rejects_noncanonical_identifiers(): void
    {
        foreach (['0', '-1', '01', '1.5', '', '1e3'] as $value) {
            try {
                new UserId($value);
                self::fail('Invalid identifier must be rejected: '.$value);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_registration_orchestrates_plain_php_ports_without_laravel(): void
    {
        $user = new User(new UserId('1'), 'Staff', 'staff@example.test', false);
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects(self::once())->method('register')->with('Staff', 'staff@example.test', 'a valid test passphrase')->willReturn($user);
        $notifications = $this->createMock(RegistrationNotifierInterface::class);
        $notifications->expects(self::once())->method('registered')->with($user->id);

        self::assertSame($user, (new RegisterUserHandler($repository, $notifications))->handle(new RegisterUserData('Staff', 'staff@example.test', 'a valid test passphrase')));
    }

    public function test_failed_registration_does_not_emit_a_registration_notification(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects(self::once())->method('register')->willThrowException(new EmailAlreadyRegistered);
        $notifications = $this->createMock(RegistrationNotifierInterface::class);
        $notifications->expects(self::never())->method('registered');
        $this->expectException(EmailAlreadyRegistered::class);

        (new RegisterUserHandler($repository, $notifications))->handle(new RegisterUserData('Staff', 'staff@example.test', 'a valid test passphrase'));
    }

    public function test_password_reset_handler_preserves_adapter_success_and_failure(): void
    {
        $passwords = $this->createMock(PasswordResetterInterface::class);
        $data = new ResetPasswordData('staff@example.test', 'token', 'a valid test passphrase');
        $passwords->expects(self::exactly(2))->method('reset')->with($data)->willReturnOnConsecutiveCalls(true, false);
        $handler = new ResetPasswordHandler($passwords);

        self::assertTrue($handler->handle($data));
        self::assertFalse($handler->handle($data));
    }
}
