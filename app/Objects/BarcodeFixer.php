<?php

namespace App\Objects;

class BarcodeFixer
{
    public static function fixUpc($upc)
    {
        $upc = str_pad(ltrim($upc, '0'), 11, '0', STR_PAD_LEFT);
        if (11 !== strlen($upc)) {
            return $upc;
        }

        $len = strlen($upc);

        switch ($len) {
            case 11:
            case 12:
                $inner10 = substr($upc, 0, 11);
                break;
            default:
                throw new Exception('Invalid upc length in calculateChecksum(' . $upc . '): ' . $len);
        }

        /*
        1.	Add the digits in the odd-numbered positions (first, third, fifth, etc.) together and multiply by three.
        2.	Add the digits in the even-numbered positions (second, fourth, sixth, etc.) to the result.
        3.	Find the result modulo 10 (i.e. the remainder when divided by 10.. 10 goes into 58 5 times with 8 leftover).
        4.	If the result is not zero, subtract the result from ten.
        */

        $even = 0;
        $odd = 0;

        for ($i = 0; $i < 11; $i++) {
            $x = $i + 1;
            if (0 == ($x % 2)) {
                $even += $inner10[$i];
            } else {
                $odd += $inner10[$i];
            }
        }

        $val = ((($odd * 3) + $even) % 10);

        if ($val == 0) {
            $check = 0;
        } else {
            $check = 10 - $val;
        }

        return $upc . $check;
    }

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
