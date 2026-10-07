<?php

namespace Tests\Unit;

use App\Services\ThaiBahtTextService;
use PHPUnit\Framework\TestCase;

/**
 * The written amount is what makes a Thai invoice hard to alter after it is issued: a
 * figure can have a digit added to it, a sentence cannot. So a wrong phrase is not a
 * cosmetic defect — it is a document that disagrees with itself, which an auditor
 * reads as tampering.
 *
 * The first two cases are transcribed from the shop's own printed invoices, so they
 * are the specification rather than an assumption about how Thai should be read.
 */
class ThaiBahtTextServiceTest extends TestCase
{
    private ThaiBahtTextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ThaiBahtTextService;
    }

    /**
     * Transcribed from หจก. รุ่งเรืองแกรนด์ invoice 69080085, which prints
     * "<<< หกพันเก้าร้อยยี่สิบห้าบาทถ้วน >>>" against a total of 6,925.00.
     */
    public function test_it_matches_the_amount_printed_on_a_real_tax_invoice(): void
    {
        $this->assertSame( 'หกพันเก้าร้อยยี่สิบห้าบาทถ้วน', $this->service->convert( 6925.00 ) );
    }

    /**
     * Transcribed from billing note 69080001, which prints
     * "<<< หนึ่งหมื่นเก้าพันสามร้อยบาทถ้วน >>>" against a total of 19,300.00.
     */
    public function test_it_matches_the_amount_printed_on_a_real_billing_note(): void
    {
        $this->assertSame( 'หนึ่งหมื่นเก้าพันสามร้อยบาทถ้วน', $this->service->convert( 19300.00 ) );
    }

    public function test_zero_is_spelled_out_rather_than_left_blank(): void
    {
        $this->assertSame( 'ศูนย์บาทถ้วน', $this->service->convert( 0 ) );
    }

    /**
     * The tens column is the irregular one: one is สิบ not หนึ่งสิบ, two is ยี่สิบ not
     * สองสิบ, and a trailing one becomes เอ็ด once something precedes it.
     */
    public function test_it_reads_the_irregular_tens_column(): void
    {
        $this->assertSame( 'หนึ่งบาทถ้วน', $this->service->convert( 1 ) );
        $this->assertSame( 'สิบบาทถ้วน', $this->service->convert( 10 ) );
        $this->assertSame( 'สิบเอ็ดบาทถ้วน', $this->service->convert( 11 ) );
        $this->assertSame( 'ยี่สิบบาทถ้วน', $this->service->convert( 20 ) );
        $this->assertSame( 'ยี่สิบเอ็ดบาทถ้วน', $this->service->convert( 21 ) );
        $this->assertSame( 'สามสิบสองบาทถ้วน', $this->service->convert( 32 ) );
    }

    /**
     * A one in the units column is เอ็ด only when it is not the whole number, so 101
     * ends in เอ็ด while 1 does not.
     */
    public function test_a_trailing_one_after_a_hundred_is_ed(): void
    {
        $this->assertSame( 'หนึ่งร้อยเอ็ดบาทถ้วน', $this->service->convert( 101 ) );
        $this->assertSame( 'หนึ่งร้อยบาทถ้วน', $this->service->convert( 100 ) );
    }

    /**
     * Thai counts in units of a million, so the digits are grouped in sixes rather than
     * threes and joined with ล้าน.
     */
    public function test_it_reads_millions_in_groups_of_six_digits(): void
    {
        $this->assertSame( 'หนึ่งล้านบาทถ้วน', $this->service->convert( 1000000 ) );
        $this->assertSame( 'หนึ่งล้านหนึ่งบาทถ้วน', $this->service->convert( 1000001 ) );
        $this->assertSame( 'สิบสองล้านสามแสนสี่หมื่นห้าพันหกร้อยเจ็ดสิบแปดบาทถ้วน', $this->service->convert( 12345678 ) );
    }

    /**
     * The catalogue holds 124 million units of stock, so a stock valuation report can
     * legitimately reach this scale.
     */
    public function test_it_reads_a_million_millions( ): void
    {
        $this->assertSame( 'หนึ่งล้านล้านบาทถ้วน', $this->service->convert( 1000000000000 ) );
    }

    public function test_satang_replace_the_word_that_closes_a_whole_amount(): void
    {
        $this->assertSame( 'หนึ่งบาทห้าสิบสตางค์', $this->service->convert( 1.50 ) );
        $this->assertSame( 'ศูนย์บาทยี่สิบห้าสตางค์', $this->service->convert( 0.25 ) );
        $this->assertSame( 'ศูนย์บาทหนึ่งสตางค์', $this->service->convert( 0.01 ) );
        $this->assertSame( 'ศูนย์บาทสิบเอ็ดสตางค์', $this->service->convert( 0.11 ) );
    }

    /**
     * Binary floating point stores 0.07 slightly below its true value, so reading the
     * satang by casting the scaled remainder rather than rounding it produces 6.
     */
    public function test_it_does_not_lose_a_satang_to_floating_point(): void
    {
        $this->assertSame( 'ศูนย์บาทเจ็ดสตางค์', $this->service->convert( 0.07 ) );
        $this->assertSame( 'หนึ่งร้อยบาทเจ็ดสตางค์', $this->service->convert( 100.07 ) );
        $this->assertSame( 'สองร้อยเก้าสิบสามบาทยี่สิบเก้าสตางค์', $this->service->convert( 293.29 ) );
    }

    /**
     * Rounding the fraction can carry into the baht. Without handling that, this would
     * read as nine hundred and ninety nine baht and one hundred satang.
     */
    public function test_a_fraction_that_rounds_up_carries_into_the_baht(): void
    {
        $this->assertSame( 'หนึ่งพันบาทถ้วน', $this->service->convert( 999.999 ) );
        $this->assertSame( 'หนึ่งบาทถ้วน', $this->service->convert( 0.999 ) );
    }

    public function test_a_negative_amount_is_marked_as_such(): void
    {
        $this->assertSame( 'ลบห้าบาทถ้วน', $this->service->convert( -5 ) );
        $this->assertSame( 'ลบหนึ่งร้อยบาทห้าสิบสตางค์', $this->service->convert( -100.50 ) );
    }

    /**
     * The VAT figures on the shop's own invoice: 6,471.96 net and 453.04 tax. Both carry
     * satang, which is the normal case for an inclusive-VAT breakdown and the case most
     * likely to expose a rounding mistake.
     */
    public function test_it_reads_the_vat_breakdown_from_the_real_invoice(): void
    {
        $this->assertSame( 'หกพันสี่ร้อยเจ็ดสิบเอ็ดบาทเก้าสิบหกสตางค์', $this->service->convert( 6471.96 ) );
        $this->assertSame( 'สี่ร้อยห้าสิบสามบาทสี่สตางค์', $this->service->convert( 453.04 ) );
    }
}
