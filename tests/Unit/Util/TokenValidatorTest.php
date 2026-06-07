<?php

/**
 * Unit tests for TokenValidator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-17
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\Shillinq\Util\TokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests token generation, hashing, verification and expiration.
 */
final class TokenValidatorTest extends TestCase
{
    /**
     * The validator under test.
     *
     * @var TokenValidator
     */
    private TokenValidator $validator;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new TokenValidator();
    }//end setUp()

    /**
     * Generated tokens are 32-character URL-safe base62 strings.
     *
     * @return void
     */
    public function testGenerateTokenStringIs32CharUrlSafe(): void
    {
        $token = $this->validator->generateTokenString();
        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{32}$/', $token);
    }//end testGenerateTokenStringIs32CharUrlSafe()

    /**
     * Generated tokens are unique across invocations.
     *
     * @return void
     */
    public function testGeneratedTokensAreUnique(): void
    {
        $a = $this->validator->generateTokenString();
        $b = $this->validator->generateTokenString();
        self::assertNotSame($a, $b);
    }//end testGeneratedTokensAreUnique()

    /**
     * A token verifies against its own bcrypt hash and the hash is not the raw token.
     *
     * @return void
     */
    public function testHashAndVerifyRoundTrip(): void
    {
        $raw  = $this->validator->generateTokenString();
        $hash = $this->validator->hashToken($raw);

        self::assertNotSame($raw, $hash, 'Stored hash must differ from the raw token');
        self::assertTrue($this->validator->verifyToken($raw, $hash));
    }//end testHashAndVerifyRoundTrip()

    /**
     * A wrong token does not verify against the stored hash.
     *
     * @return void
     */
    public function testVerifyRejectsWrongToken(): void
    {
        $hash = $this->validator->hashToken('correct-token');
        self::assertFalse($this->validator->verifyToken('wrong-token', $hash));
    }//end testVerifyRejectsWrongToken()

    /**
     * Empty inputs never verify.
     *
     * @return void
     */
    public function testVerifyRejectsEmptyInputs(): void
    {
        self::assertFalse($this->validator->verifyToken('', 'hash'));
        self::assertFalse($this->validator->verifyToken('raw', ''));
    }//end testVerifyRejectsEmptyInputs()

    /**
     * A past expiry is expired; a future expiry is not.
     *
     * @return void
     */
    public function testIsExpired(): void
    {
        $now  = 1700000000;
        $past = gmdate('Y-m-d\TH:i:s\Z', ($now - 10));
        $fut  = gmdate('Y-m-d\TH:i:s\Z', ($now + 10));

        self::assertTrue($this->validator->isExpired($past, $now));
        self::assertFalse($this->validator->isExpired($fut, $now));
    }//end testIsExpired()

    /**
     * An unparseable expiry is treated as expired (fail-closed).
     *
     * @return void
     */
    public function testUnparseableExpiryIsExpired(): void
    {
        self::assertTrue($this->validator->isExpired('not-a-date', 1700000000));
    }//end testUnparseableExpiryIsExpired()
}//end class
