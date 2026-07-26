<?php
declare(strict_types=1);

const PASSWORD_PEPPER = 'finlytics-nesfejahan-v1';

function hash_password(string $password): string
{
    return hash('sha256', PASSWORD_PEPPER . ':' . to_en_digits($password));
}

function verify_password(string $password, string $passwordHash): bool
{
    return hash_equals(hash_password($password), $passwordHash);
}

function seed_default_user(PDO $pdo): void
{
    $phone = '09131176583';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    $hash = hash_password('123456789');
    if ($row) {
        $pdo->prepare('UPDATE users SET password_hash = ?, is_active = 1, full_name = ? WHERE id = ?')
            ->execute([$hash, 'علی قضاوی', $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO users (phone, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$phone, $hash, 'علی قضاوی']);
    }
}

function create_token(PDO $pdo, array $user): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('INSERT INTO auth_tokens (token, user_id, created_at) VALUES (?, ?, ?)')
        ->execute([$token, $user['id'], utc_now()]);
    return $token;
}

function get_user_by_token(PDO $pdo, ?string $authorization): ?array
{
    if (!$authorization) {
        return null;
    }
    $raw = $authorization;
    if (stripos($raw, 'Bearer ') === 0) {
        $raw = trim(substr($raw, 7));
    }
    if ($raw === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.phone, u.full_name, u.is_active
         FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ?'
    );
    $stmt->execute([$raw]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        return null;
    }
    return $user;
}

function auth_login(PDO $pdo, string $phone, string $password): array
{
    $phone = preg_replace('/[\s\-]/', '', to_en_digits(trim($phone)));
    $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active'] || !verify_password($password, $user['password_hash'])) {
        throw new InvalidArgumentException('شماره موبایل یا رمز عبور نادرست است');
    }
    return [$user, create_token($pdo, $user)];
}
