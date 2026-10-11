<?php

declare(strict_types=1);

/** ARPABET from the CMU Pronouncing Dictionary to a broad IPA transcription. */
final class Arpabet
{
    private const MAP = [
        'AA' => 'ɑ', 'AE' => 'æ', 'AH' => 'ʌ', 'AO' => 'ɔ', 'AW' => 'aʊ', 'AY' => 'aɪ',
        'B' => 'b', 'CH' => 'tʃ', 'D' => 'd', 'DH' => 'ð', 'EH' => 'ɛ', 'ER' => 'ɝ',
        'EY' => 'eɪ', 'F' => 'f', 'G' => 'ɡ', 'HH' => 'h', 'IH' => 'ɪ', 'IY' => 'i',
        'JH' => 'dʒ', 'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n', 'NG' => 'ŋ',
        'OW' => 'oʊ', 'OY' => 'ɔɪ', 'P' => 'p', 'R' => 'ɹ', 'S' => 's', 'SH' => 'ʃ',
        'T' => 't', 'TH' => 'θ', 'UH' => 'ʊ', 'UW' => 'u', 'V' => 'v', 'W' => 'w',
        'Y' => 'j', 'Z' => 'z', 'ZH' => 'ʒ',
    ];

    /** @param list<string> $phones */
    public static function toIpa(array $phones): ?string
    {
        $out = '';
        foreach ($phones as $phone) {
            $phone = strtoupper($phone);
            $stress = '';
            if ($phone !== '' && ctype_digit($phone[strlen($phone) - 1])) {
                $stress = $phone[strlen($phone) - 1];
                $phone = substr($phone, 0, -1);
            }
            if (!isset(self::MAP[$phone])) {
                return null;
            }
            $glyph = self::MAP[$phone];
            if ($phone === 'AH' && $stress === '0') {
                $glyph = 'ə';
            } elseif ($phone === 'ER' && $stress === '0') {
                $glyph = 'ɚ';
            }
            if ($stress === '1') {
                $out .= 'ˈ';
            } elseif ($stress === '2') {
                $out .= 'ˌ';
            }
            $out .= $glyph;
        }
        return $out === '' ? null : '/' . $out . '/';
    }
}
