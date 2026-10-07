<?php

namespace App\Services;

/**
 * Spells an amount out in Thai words, the way it is printed between angle brackets
 * on a Thai invoice: "หกพันเก้าร้อยยี่สิบห้าบาทถ้วน".
 *
 * The written amount is not decoration. It is what makes the document hard to alter
 * after the fact — a figure can have a digit added to it, a sentence cannot — which
 * is why Thai invoices and cheques carry it and why an auditor reads it rather than
 * the numerals.
 *
 * Matches Excel's BAHTTEXT, because that is the reference every Thai bookkeeper
 * already knows and any disagreement with it reads as a bug in this system.
 */
class ThaiBahtTextService
{
    /**
     * @var array<int, string>
     */
    private const DIGITS = [ 'ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า' ];

    /**
     * Indexed by position from the right within a six digit group: 0 is the units
     * column and carries no word of its own.
     *
     * @var array<int, string>
     */
    private const PLACES = [ '', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน' ];

    private const GROUP_SIZE = 6;

    /**
     * The full phrase, baht and satang included.
     *
     * @param float|int|string $amount
     */
    public function convert( float|int|string $amount ): string
    {
        $amount = (float) $amount;

        $isNegative = $amount < 0;
        $amount = abs( $amount );

        /**
         * Rounded before splitting, not after. Reading the integer and fractional parts
         * off an unrounded float turns 1.005 into one baht and zero satang, which is a
         * different amount from the one printed in figures beside it.
         */
        $amount = round( $amount, 2 );

        $baht = (int) floor( $amount );

        /**
         * Derived by rounding the scaled remainder rather than by casting it. Casting
         * truncates, and binary floating point represents 0.07 slightly below its true
         * value, so a cast would read 7 satang as 6.
         */
        $satang = (int) round( ( $amount - $baht ) * 100 );

        /**
         * Rounding the fraction can carry into the baht: 999.999 rounds to 1000.00, and
         * without this the phrase would read nine hundred and ninety nine baht and one
         * hundred satang.
         */
        if ( $satang === 100 ) {
            $baht++;
            $satang = 0;
        }

        $words = $this->bahtPhrase( $baht ) . $this->satangPhrase( $satang );

        return ( $isNegative ? 'ลบ' : '' ) . $words;
    }

    /**
     * The baht part, including the word บาท.
     */
    private function bahtPhrase( int $baht ): string
    {
        if ( $baht === 0 ) {
            /**
             * Zero is spelled out rather than omitted. "ยี่สิบห้าสตางค์" on its own would
             * leave a reader checking whether the baht column had been left off.
             */
            return 'ศูนย์บาท';
        }

        return $this->integerToThai( (string) $baht ) . 'บาท';
    }

    /**
     * The satang part, or the word that closes a whole amount.
     */
    private function satangPhrase( int $satang ): string
    {
        /**
         * ถ้วน means "exactly", and its absence is what tells a reader that satang follow.
         * Leaving it off a whole amount leaves room to append them.
         */
        if ( $satang === 0 ) {
            return 'ถ้วน';
        }

        return $this->integerToThai( (string) $satang ) . 'สตางค์';
    }

    /**
     * Reads a whole number, however long.
     *
     * Thai counts in units of a million rather than a thousand, so the digits are taken
     * six at a time and joined with ล้าน. The recursion is what allows ล้านล้าน and
     * beyond to come out right without a table of names for each scale.
     */
    private function integerToThai( string $digits ): string
    {
        $digits = ltrim( $digits, '0' );

        if ( $digits === '' ) {
            return '';
        }

        if ( strlen( $digits ) > self::GROUP_SIZE ) {
            $head = substr( $digits, 0, strlen( $digits ) - self::GROUP_SIZE );
            $tail = substr( $digits, -self::GROUP_SIZE );

            return $this->integerToThai( $head ) . 'ล้าน' . $this->groupToThai( $tail );
        }

        return $this->groupToThai( $digits );
    }

    /**
     * Reads at most six digits.
     */
    private function groupToThai( string $group ): string
    {
        /**
         * Stripped so the position rules below see the number's real length. A group
         * arriving as "000001" is one, not a six digit number, and reading it with its
         * padding would produce เอ็ด where หนึ่ง belongs.
         */
        $group = ltrim( $group, '0' );

        if ( $group === '' ) {
            return '';
        }

        $length = strlen( $group );
        $words = '';

        for ( $index = 0; $index < $length; $index++ ) {
            $digit = (int) $group[ $index ];

            if ( $digit === 0 ) {
                continue;
            }

            $position = $length - $index - 1;

            /**
             * The tens column is irregular: one is สิบ rather than หนึ่งสิบ, and two is
             * ยี่สิบ rather than สองสิบ. Nothing else in the number behaves this way.
             */
            if ( $position === 1 ) {
                $words .= match ( $digit ) {
                    1 => 'สิบ',
                    2 => 'ยี่สิบ',
                    default => self::DIGITS[ $digit ] . 'สิบ',
                };

                continue;
            }

            /**
             * A trailing one is เอ็ด when something precedes it: 11 is สิบเอ็ด and 101 is
             * หนึ่งร้อยเอ็ด, but 1 on its own is หนึ่ง.
             */
            if ( $position === 0 && $digit === 1 && $length > 1 ) {
                $words .= 'เอ็ด';

                continue;
            }

            $words .= self::DIGITS[ $digit ] . self::PLACES[ $position ];
        }

        return $words;
    }
}
