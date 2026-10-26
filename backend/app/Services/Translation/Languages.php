<?php

namespace App\Services\Translation;

/**
 * Language codes across the app, Whisper and NLLB. The app and Whisper use
 * ISO 639-1 codes ("en", "ja", "tl"), except that the app calls Filipino "fil";
 * NLLB uses FLORES-200 codes ("eng_Latn", "tgl_Latn").
 */
class Languages
{
    /**
     * Whisper's languages that NLLB can translate, as FLORES-200 codes.
     */
    private const NLLB = [
        'af' => 'afr_Latn', 'am' => 'amh_Ethi', 'ar' => 'arb_Arab', 'as' => 'asm_Beng', 'az' => 'azj_Latn',
        'be' => 'bel_Cyrl', 'bg' => 'bul_Cyrl', 'bn' => 'ben_Beng', 'bo' => 'bod_Tibt', 'bs' => 'bos_Latn',
        'ca' => 'cat_Latn', 'cs' => 'ces_Latn', 'cy' => 'cym_Latn', 'da' => 'dan_Latn', 'de' => 'deu_Latn',
        'el' => 'ell_Grek', 'en' => 'eng_Latn', 'es' => 'spa_Latn', 'et' => 'est_Latn', 'eu' => 'eus_Latn',
        'fa' => 'pes_Arab', 'fi' => 'fin_Latn', 'fo' => 'fao_Latn', 'fr' => 'fra_Latn', 'gl' => 'glg_Latn',
        'gu' => 'guj_Gujr', 'ha' => 'hau_Latn', 'he' => 'heb_Hebr', 'hi' => 'hin_Deva', 'hr' => 'hrv_Latn',
        'ht' => 'hat_Latn', 'hu' => 'hun_Latn', 'hy' => 'hye_Armn', 'id' => 'ind_Latn', 'is' => 'isl_Latn',
        'it' => 'ita_Latn', 'ja' => 'jpn_Jpan', 'jw' => 'jav_Latn', 'ka' => 'kat_Geor', 'kk' => 'kaz_Cyrl',
        'km' => 'khm_Khmr', 'kn' => 'kan_Knda', 'ko' => 'kor_Hang', 'lb' => 'ltz_Latn', 'ln' => 'lin_Latn',
        'lo' => 'lao_Laoo', 'lt' => 'lit_Latn', 'lv' => 'lvs_Latn', 'mi' => 'mri_Latn', 'mk' => 'mkd_Cyrl',
        'ml' => 'mal_Mlym', 'mn' => 'khk_Cyrl', 'mr' => 'mar_Deva', 'ms' => 'zsm_Latn', 'mt' => 'mlt_Latn',
        'my' => 'mya_Mymr', 'ne' => 'npi_Deva', 'nl' => 'nld_Latn', 'nn' => 'nno_Latn', 'no' => 'nob_Latn',
        'oc' => 'oci_Latn', 'pa' => 'pan_Guru', 'pl' => 'pol_Latn', 'ps' => 'pbt_Arab', 'pt' => 'por_Latn',
        'ro' => 'ron_Latn', 'ru' => 'rus_Cyrl', 'sd' => 'snd_Arab', 'si' => 'sin_Sinh', 'sk' => 'slk_Latn',
        'sl' => 'slv_Latn', 'sn' => 'sna_Latn', 'so' => 'som_Latn', 'sq' => 'als_Latn', 'sr' => 'srp_Cyrl',
        'su' => 'sun_Latn', 'sv' => 'swe_Latn', 'sw' => 'swh_Latn', 'ta' => 'tam_Taml', 'te' => 'tel_Telu',
        'tg' => 'tgk_Cyrl', 'th' => 'tha_Thai', 'tk' => 'tuk_Latn', 'tl' => 'tgl_Latn', 'tr' => 'tur_Latn',
        'tt' => 'tat_Cyrl', 'uk' => 'ukr_Cyrl', 'ur' => 'urd_Arab', 'uz' => 'uzn_Latn', 'vi' => 'vie_Latn',
        'yi' => 'ydd_Hebr', 'yo' => 'yor_Latn', 'yue' => 'yue_Hant', 'zh' => 'zho_Hans',
    ];

    /**
     * Written without spaces between words; subtitles join and wrap them differently.
     */
    private const WITHOUT_SPACES = ['ja', 'zh', 'yue'];

    /**
     * The ISO 639-1 code for an app or Whisper language; null when it isn't known ("auto", empty).
     */
    public static function normalize(?string $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            '', 'auto' => null,
            'fil' => 'tl',
            default => $language,
        };
    }

    /**
     * NLLB's code for a language, or null when NLLB can't translate it.
     */
    public static function nllb(?string $language): ?string
    {
        return self::NLLB[self::normalize($language)] ?? null;
    }

    public static function same(?string $first, ?string $second): bool
    {
        return self::normalize($first) !== null && self::normalize($first) === self::normalize($second);
    }

    public static function writtenWithoutSpaces(?string $language): bool
    {
        return in_array(self::normalize($language), self::WITHOUT_SPACES, true);
    }
}
