<?php

namespace StreamEngine\Core;

use Random\RandomException;

class Cryptography
{
    public const string B62_DICTIONARY = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const array OPAQUE_ID_ROUND_KEYS = [0x9e3779b1, 0x85ebca77, 0xc2b2ae3d, 0x27d4eb2f];

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

    /** Matches the browser-side CMS.encodeId() representation. */
    public static function encodeOpaqueId(int $id): ?string
    {
        if ($id <= 0 || $id > 0xffffffff) {
            return null;
        }

        $left = ($id >> 16) & 0xffff;
        $right = $id & 0xffff;
        foreach (self::OPAQUE_ID_ROUND_KEYS as $key) {
            $next = ($left ^ self::opaqueIdRound($right, $key)) & 0xffff;
            $left = $right;
            $right = $next;
        }

        $block = (($left << 16) | $right) & 0xffffffff;

        return str_pad(base_convert((string) $block, 10, 36), 7, '0', STR_PAD_LEFT);
    }

    private static function opaqueIdRound(int $half, int $key): int
    {
        $x = ($half ^ $key) & 0xffffffff;
        $x = self::multiply32($x, 0x2545f491);
        $x = ($x ^ ($x >> 13)) & 0xffffffff;
        $x = self::multiply32($x, 0x27220a95);
        $x = ($x ^ ($x >> 15)) & 0xffffffff;

        return $x & 0xffff;
    }

    private static function multiply32(int $a, int $b): int
    {
        $low = ($a & 0xffff) * $b;
        $high = (($a >> 16) & 0xffff) * ($b & 0xffff);

        return (int) (($low + (($high & 0xffff) << 16)) & 0xffffffff);
    }
}
