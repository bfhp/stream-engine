<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Cryptography;

final class CryptographyTest extends TestCase
{
    public function testGenerateBase62UsesDefaultLength(): void
    {
        $value = Cryptography::generateBase62();

        $this->assertSame(10, strlen($value));
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z]+$/', $value);
    }

    public function testGenerateBase62UsesRequestedLength(): void
    {
        $value = Cryptography::generateBase62(32);

        $this->assertSame(32, strlen($value));
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z]+$/', $value);
    }

    public function testGenerateBase62ReturnsEmptyStringForZeroLength(): void
    {
        $this->assertSame('', Cryptography::generateBase62(0));
    }

    public function testGenerateBase62ReturnsEmptyStringForNegativeLength(): void
    {
        $this->assertSame('', Cryptography::generateBase62(-5));
    }

    public function testDictionaryHasSixtyTwoCharacters(): void
    {
        $this->assertSame(62, strlen(Cryptography::B62_DICTIONARY));
        $this->assertSame(
            strlen(Cryptography::B62_DICTIONARY),
            count(array_unique(str_split(Cryptography::B62_DICTIONARY)))
        );
    }

    public function testGenerateBase62OnlyUsesDictionaryCharactersAcrossManyRuns(): void
    {
        $combined = '';

        for ($i = 0; $i < 50; $i++) {
            $combined .= Cryptography::generateBase62(8);
        }

        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z]+$/', $combined);

        foreach (str_split($combined) as $char) {
            $this->assertStringContainsString($char, Cryptography::B62_DICTIONARY);
        }
    }
}
