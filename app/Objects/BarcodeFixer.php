<?php

namespace App\Objects;

class BarcodeFixer
{
    // Truncates or 0 pads string length to 13 characters
    public static function fixLength(string $upc): string
    {
        if (strlen($upc) === 14) {
            return substr($upc, 1);
        }
        while (strlen($upc) < 13) {
            $upc = '0' . $upc;
        }
        return $upc;
    }

    public static function isValid($upc): bool
    {
        if (strlen($upc) !== 13) {
            return false;
        }

        $checkOne = intval(substr($upc, 12, 1));
        $barcode = substr($upc, 0, 12);
        $len = strlen($barcode);

        for ($i = 0; $i < $len; $i++) {
            if (!is_numeric($barcode[$i])) {
                return false;
            }
        }

        $checkTwo = BarcodeFixer::calculateMod10Checksum($barcode);
        return ($checkOne === $checkTwo);
    }

    public static function fixUpc($upc)
    {
        $upc = str_pad(ltrim($upc, '0'), 11, '0', STR_PAD_LEFT);
        if (11 !== strlen($upc)) {
            return $upc;
        }

        if (!is_numeric($upc)) {
            return '0';
        }

        try {
            $check = BarcodeFixer::calculateMod10Checksum(substr($upc, 0, 11));
        } catch (\Exception $e) {
            return '0';
        }

        return '0' . $upc . $check;
    }

    /*
    1.	Add the digits in the odd-numbered positions (first, third, fifth, etc.) together and multiply by three.
    2.	Add the digits in the even-numbered positions (second, fourth, sixth, etc.) to the result.
    3.	Find the result modulo 10 (i.e. the remainder when divided by 10.. 10 goes into 58 5 times with 8 leftover).
    4.	If the result is not zero, subtract the result from ten.
    */
    public static function calculateMod10Checksum(string $barcode): int
    {
        $len = strlen($barcode);

        $even = 0;
        $odd = 0;

        for ($i = 0; $i < $len; $i++) {
            $x = $len - $i;
            if (0 == ($x % 2)) {
                $even += $barcode[$i];
            } else {
                $odd += $barcode[$i];
            }
        }

        $oddx3 = $odd * 3;

        $val = (($oddx3 + $even) % 10);

        if ($val == 0) {
            $check = 0;
        } else {
            $check = 10 - $val;
        }

        return $check;
    }
}
