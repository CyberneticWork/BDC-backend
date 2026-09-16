<?php

namespace App\Models;

use App\Services\HrJwtToken;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        if (HrJwtToken::isJwt($token)) {
            $payload = HrJwtToken::decode($token);
            if (!$payload) {
                return null;
            }

            if (!isset($payload['tid']) || !ctype_digit((string) $payload['tid'])) {
                return null;
            }

            $accessToken = static::query()->find($payload['tid']);
            if (!$accessToken) {
                return null;
            }
            if ((int) $accessToken->tokenable_id !== (int) $payload['sub']) {
                return null;
            }
            if ((string) $accessToken->name !== 'jwt:'.$payload['jti']) {
                return null;
            }

            return $accessToken;
        }

        return parent::findToken($token);
    }
}
