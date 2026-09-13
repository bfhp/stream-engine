<?php

namespace StreamEngine\Core;

use Random\RandomException;

class Cryptography
{
    public const string B62_DICTIONARY = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * @throws RandomException
     */
    public static function generateBase62(int $length = 10): string
    {
        $max = strlen(self::B62_DICTIONARY) - 1;

        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= self::B62_DICTIONARY[random_int(0, $max)];
        }

        return $result;
    }
}
