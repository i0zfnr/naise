<?php

namespace App\Services;

class WhatsAppNumber
{
    public function normalize(string $number): ?string
    {
        $number = trim($number);
        if (! preg_match('/^\+?[0-9().\s-]+$/', $number)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $number);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($number, '+')) {
            // International numbers already include the country calling code.
        } elseif (str_starts_with($digits, '0')) {
            $digits = '60'.substr($digits, 1);
        } elseif (str_starts_with($digits, '60')) {
            // Accept Malaysian numbers without a leading plus sign.
        } elseif (str_starts_with($digits, '1') && in_array(strlen($digits), [9, 10])) {
            $digits = '60'.$digits;
        } else {
            return null;
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? '+'.$digits : null;
    }
}
