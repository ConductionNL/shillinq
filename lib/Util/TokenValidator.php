<?php

/**
 * Confirmation Token Validator
 *
 * Generates and validates appointment confirmation tokens for the
 * bookings-confirm-flow capability. Raw tokens are 32-character URL-safe
 * base62 strings; only their bcrypt hash is persisted on the ConfirmationToken
 * register, and validation uses a constant-time hash comparison to prevent
 * timing attacks (REQ-BCF-002, REQ-BCF-004, REQ-BCF-017).
 *
 * @category Util
 * @package  OCA\Shillinq\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Util;

/**
 * Stateless helper for confirmation token generation and validation.
 */
class TokenValidator
{
    /**
     * Length of the generated raw token string.
     *
     * @var int
     */
    private const TOKEN_LENGTH = 32;

    /**
     * Bcrypt cost factor used when hashing tokens.
     *
     * @var int
     */
    private const BCRYPT_COST = 12;

    /**
     * Base62 alphabet used for URL-safe token strings.
     *
     * @var string
     */
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Generate a cryptographically random 32-character URL-safe base62 token.
     *
     * @return string The raw token string (deliver to customer; never persist).
     */
    public function generateTokenString(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $token          = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, ($alphabetLength - 1))];
        }

        return $token;
    }//end generateTokenString()

    /**
     * Hash a raw token string for secure storage using bcrypt.
     *
     * @param string $rawToken The raw token string.
     *
     * @return string The bcrypt hash.
     */
    public function hashToken(string $rawToken): string
    {
        return password_hash($rawToken, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }//end hashToken()

    /**
     * Verify a submitted raw token against a stored bcrypt hash.
     *
     * Uses password_verify(), which performs a constant-time comparison to
     * resist timing attacks.
     *
     * @param string $rawToken   The token submitted by the customer.
     * @param string $storedHash The bcrypt hash stored on the ConfirmationToken.
     *
     * @return bool True when the token matches the stored hash.
     */
    public function verifyToken(string $rawToken, string $storedHash): bool
    {
        if ($rawToken === '' || $storedHash === '') {
            return false;
        }

        return password_verify($rawToken, $storedHash);
    }//end verifyToken()

    /**
     * Determine whether a token record is expired relative to a reference time.
     *
     * @param string $expiresAt ISO 8601 UTC expiry timestamp.
     * @param int    $now       Reference UNIX timestamp (defaults to time()).
     *
     * @return bool True when the token has expired.
     */
    public function isExpired(string $expiresAt, ?int $now=null): bool
    {
        $now       = ($now ?? time());
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs === false) {
            // Unparseable expiry is treated as expired (fail-closed).
            return true;
        }

        return $expiresTs <= $now;
    }//end isExpired()
}//end class
