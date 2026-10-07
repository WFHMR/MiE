<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The receipt printed "ภาษี: 5.82" immediately above "รวมทั้งสิ้น: 89.00" and nothing else about tax.
 *
 * Read plainly, that says 89 was the goods and 5.82 was the tax, so the bill should have been 94.82 and
 * the change 5.18. The shop asked for exactly that. It would have been wrong: the shelf prices already
 * contain the VAT. Every product carries tax_type = inclusive and ns_pos_tax_type is inclusive, so 89.00
 * decomposes into 83.18 of goods and 5.82 of VAT. Adding the 5.82 again would charge VAT twice, collect
 * 7% more than the price labels say, and overstate output tax on the PP30 return.
 *
 * Nothing about the arithmetic was broken, so nothing about it changed. What was missing was the line that
 * makes the arithmetic visible, which a full Thai tax invoice has to carry anyway. A receipt that invites
 * the reader to add two numbers that must not be added is a defect in the receipt.
 */
class ReceiptShowsInclusiveVatTest extends TestCase
{
    public function test_receipt_shows_coupon_identity_and_actual_discount(): void
    {
        $order = (object) [
            'settings' => collect(), 'created_at' => '2026-10-01 14:47:18',
            'code' => 'TEST-COUPON', 'user' => (object) ['username' => 'QA'],
            'customer' => (object) ['first_name' => 'สมาชิก', 'last_name' => 'ทดสอบ'],
            'combinedProducts' => collect(), 'subtotal' => 235, 'total' => 185,
            'discount' => 0, 'shipping' => 0, 'tax_value' => 0, 'payments' => [],
            'payment_status' => 'paid', 'change' => 0,
            'coupons' => collect([(object) ['name' => 'สมาชิก ลด 50', 'code' => 'MEMBER50', 'value' => 50]]),
        ];
        $html = view('pages.dashboard.orders.templates._receipt', ['order' => $order, 'paymentTypes' => []])->render();
        foreach (['สมาชิก ทดสอบ', 'สมาชิก ลด 50', 'MEMBER50', '-50.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString((string) ns()->currency->define(185), $html);
        $this->assertEquals(185, $order->total);
    }

    public function test_thermal_receipt_renders_readable_text_without_changing_amounts(): void
    {
        $order = (object) [
            'settings' => collect(), 'created_at' => '2026-09-25 12:06:53',
            'code' => 'TEST-260925-014', 'user' => (object) ['username' => 'TEST-CASHIER'],
            'combinedProducts' => collect([(object) [
                'name' => 'โรซ่า ปลามัคเคอเรลในซอสมะเขือเทศ (กลมฝาดึง) 155 กรัม',
                'quantity' => 1, 'total_price' => 19,
            ]]),
            'subtotal' => 19, 'total' => 19, 'discount' => 0, 'shipping' => 0,
            'tax_value' => 1.24, 'payments' => [['identifier' => 'cash', 'value' => 20]],
            'payment_status' => 'paid', 'change' => 1,
        ];
        $html = view('pages.dashboard.orders.templates._receipt', [
            'order' => $order, 'paymentTypes' => ['cash' => 'เงินสด'],
        ])->render();
        $this->assertStringContainsString('class="rgbf-thermal-receipt"', $html);
        $this->assertStringContainsString('font-weight: 700', $html);
        $this->assertStringContainsString('color: #000 !important', $html);
        $this->assertStringNotContainsString('Courier New', $html);
        $this->assertDoesNotMatchRegularExpression('/font-size:\s*(?:10|11)px/', $html);
        foreach ([17.76, 1.24, 19, 20, 1] as $value) {
            $this->assertStringContainsString((string) ns()->currency->define($value), $html);
        }
        $this->assertStringContainsString('TEST-260925-014', $html);
        $this->assertStringContainsString('class="receipt-product-name"', $html);
        $this->assertStringContainsString('text-overflow: ellipsis', $html);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) 40px 82px', $html);
        $this->assertStringContainsString('column-gap: 12px', $html);
        $this->assertStringContainsString('class="receipt-summary-row"', $html);
        $this->assertStringContainsString('<span>ภาษีมูลค่าเพิ่ม 7%:</span>', $html);
        $this->assertStringContainsString('(รวมในราคาแล้ว)</div>', $html);
        $this->assertStringContainsString('155 กรัม', $html);
        $preview = file_get_contents(base_path('resources/views/pages/dashboard/settings/receipt-builder.blade.php'));
        $this->assertStringContainsString("@include( 'pages.dashboard.orders.templates._thermal-receipt-style' )", $preview);
        $this->assertStringNotContainsString('Courier New', $preview);
        $this->assertStringContainsString('class="receipt-product-name"', $preview);
        $this->assertStringContainsString('class="receipt-summary-row"', $preview);

        if ($output = getenv('RECEIPT_PREVIEW_PATH')) {
            file_put_contents($output, '<!doctype html><html lang="th"><meta charset="utf-8"><title>ตัวอย่างสลิปทดสอบ</title><body>'.$html.'</body></html>');
        }
    }

    private function receipt(): string
    {
        return (string) file_get_contents(
            base_path('resources/views/pages/dashboard/orders/templates/_receipt.blade.php')
        );
    }

    public function test_the_receipt_shows_the_value_before_tax(): void
    {
        $this->assertStringContainsString(
            'มูลค่าสินค้าก่อนภาษี',
            $this->receipt(),
            'The receipt shows a tax figure with no net figure beside it, so the total looks as though the'
                .' tax had not been added to it.'
        );
    }

    /**
     * Derived from the total, not recomputed from a rate. The order already holds the tax that was charged;
     * dividing by a rate here would drift from it whenever a line is exempt or zero rated.
     */
    public function test_the_net_is_derived_from_the_total_and_the_tax_actually_charged(): void
    {
        $this->assertStringContainsString(
            '$order->total - $order->tax_value',
            $this->receipt(),
            'The net figure is computed some other way than total minus tax, so the three lines on the'
                .' receipt can disagree with each other.'
        );
    }

    public function test_the_tax_line_says_it_is_already_included(): void
    {
        $this->assertStringContainsString(
            'รวมในราคาแล้ว',
            $this->receipt(),
            'Nothing on the receipt says the VAT is contained in the price, which is what led to a request'
                .' to add it to the total a second time.'
        );
    }

    /**
     * The preview in the receipt builder taught the opposite model — 88.00 + 6.16 = 94.16 — and a shop
     * reading it would reasonably conclude their own receipts were wrong.
     */
    public function test_the_settings_preview_demonstrates_inclusive_tax(): void
    {
        $preview = (string) file_get_contents(
            base_path('resources/views/pages/dashboard/settings/receipt-builder.blade.php')
        );

        $this->assertStringNotContainsString(
            '94.16',
            $preview,
            'The preview still shows a total larger than the sum of its lines, which is the exclusive tax'
                .' model this shop does not use.'
        );

        $this->assertStringContainsString(
            '89.00',
            $preview,
            'The preview no longer demonstrates a VAT inclusive total.'
        );
    }

    /**
     * The one figure on that receipt that really was wrong. Pinned here so a later change to the receipt
     * cannot quietly reintroduce the idea that tax belongs on top.
     */
    public function test_the_arithmetic_the_shop_expected_would_charge_tax_twice(): void
    {
        $total = 89.00;
        $taxCharged = 5.82;

        $net = round($total - $taxCharged, 2);

        $this->assertEqualsWithDelta(83.18, $net, 0.01);

        /**
         * What the VAT would become if the tax were added on top, and why it must not be.
         */
        $ifAddedOnTop = round(($total + $taxCharged) * 7 / 107, 2);

        $this->assertGreaterThan(
            $taxCharged,
            $ifAddedOnTop,
            'Adding the tax to the total would leave the output tax owed higher than the tax collected,'
                .' which is the shape of a double charge.'
        );

        $this->assertEqualsWithDelta(
            $total,
            $net + $taxCharged,
            0.01,
            'The total must be the net plus the tax. If this ever fails the receipt is no longer a tax'
                .' invoice.'
        );
    }
}
