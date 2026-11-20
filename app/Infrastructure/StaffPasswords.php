<?php
declare(strict_types=1);
namespace Kios\Infrastructure;

final class StaffPasswords
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function hash(string $password): string
    {
        self::validate($password);
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function validate(string $password): void
    {
        // Keep within bcrypt's byte limit; PASSWORD_DEFAULT can evolve later.
        if (strlen($password) > 64) throw new \DomainException('That password is too long. Use a shorter phrase.');
        if (preg_match('/\A.{14,64}\z/us', $password) !== 1 || trim($password) === '' || str_contains($password, "\0")) {
            throw new \DomainException('Use a password with at least 14 characters.');
        }
    }

    public static function verifies(mixed $password, string $hash): bool
    {
        return is_string($password) && strlen($password) <= 64 && !str_contains($password, "\0")
            && password_verify($password, $hash);
    }
}
