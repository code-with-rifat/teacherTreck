<?php

declare(strict_types=1);

namespace Medico\Auth;

use Medico\Http\Request;
use Medico\Http\Response;
use PDO;

final class AuthMiddleware
{
    /** @var PDO */
    private $db;
    /** @var array */
    private $jwtConfig;

    public function __construct(PDO $db, array $jwtConfig)
    {
        $this->db = $db;
        $this->jwtConfig = $jwtConfig;
    }

    /**
     * @param list<string>|null $roles Allowed roles; null = any authenticated
     * @return array Authenticated user row
     */
    public function requireUser(Request $request, ?array $roles = null): array
    {
        $token = $request->bearerToken();
        if (!$token) {
            Response::error('Authentication required', 401);
        }

        try {
            $payload = Jwt::decode($token, $this->jwtConfig['secret'], $this->jwtConfig['issuer']);
        } catch (\Throwable $e) {
            Response::error('Invalid or expired token', 401);
        }

        $stmt = $this->db->prepare('SELECT id, email, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) ($payload['sub'] ?? 0)]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            Response::error('Account not active', 403);
        }

        if ($roles !== null && !in_array($user['role'], $roles, true)) {
            Response::error('Insufficient permissions', 403);
        }

        return $user;
    }
}
