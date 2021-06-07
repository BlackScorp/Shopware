<?php

namespace MollieShopware\Tests\Services\Mollie\Payments\Requests;

use MollieShopware\Services\Mollie\Payments\Models\Payment;
use MollieShopware\Services\Mollie\Payments\Models\PaymentAddress;
use MollieShopware\Services\Mollie\Payments\Models\PaymentLineItem;
use MollieShopware\Services\Mollie\Payments\Requests\Przelewy24;
use MollieShopware\Tests\Utils\Traits\PaymentTestTrait;
use PHPUnit\Framework\TestCase;


class Przelewy24Test extends TestCase
{
    use PaymentTestTrait;


    /**
     * @var Przelewy24
     */
    private $payment;

    /**
     * @var PaymentAddress
     */
    private $address;

    /**
     * @var PaymentLineItem
     */
    private $lineItem;

    /**
     *
     */
    public function setUp(): void
    {
        $this->payment = new Przelewy24();

        $this->address = $this->getAddressFixture();
        $this->lineItem = $this->getLineItemFixture();

        $this->payment->setPayment(
            new Payment(
                'UUID-123',
                'Order UUID-123',
                '20004',
                $this->address,
                $this->address,
                49.98,
                [$this->lineItem],
                'USD',
                'de_DE',
                'https://local/redirect',
                'https://local/notify'
            )
        );
    }

    /**
     * This test verifies that the Payments-API request
     * for our payment is correct.
     */
    public function testPaymentsAPI()
    {
        $expected = [
            'method' => 'przelewy24',
            'amount' => [
                'currency' => 'USD',
                'value' => '49.98',
            ],
            'description' => 'Order UUID-123',
            'redirectUrl' => 'https://local/redirect',
            'webhookUrl' => 'https://local/notify',
            'locale' => 'de_DE',
            'billingEmail' => 'dev@mollie.local',
        ];

        $requestBody = $this->payment->buildBodyPaymentsAPI();

        $this->assertEquals($expected, $requestBody);
    }

    /**
     * This test verifies that the Orders-API request
     * for our payment is correct.
     */
    public function testOrdersAPI()
    {
        $expected = [
            'method' => 'przelewy24',
            'amount' => [
                'currency' => 'USD',
                'value' => '49.98',
            ],
            'redirectUrl' => 'https://local/redirect',
            'webhookUrl' => 'https://local/notify',
            'locale' => 'de_DE',
            'orderNumber' => '20004',
            'payment' => [
                'webhookUrl' => 'https://local/notify',
            ],
            'billingAddress' => $this->getExpectedAddressStructure($this->address),
            'shippingAddress' => $this->getExpectedAddressStructure($this->address),
            'lines' => [
                $this->getExpectedLineItemStructure($this->lineItem),
            ],
            'metadata' => [],
        ];

        $requestBody = $this->payment->buildBodyOrdersAPI();

        $this->assertSame($expected, $requestBody);
    }

    /**
     * This test verifies that our billing email is
     * correctly existing where necessary.
     * Please keep in mind, this must NOT exist in the orders API!
     */
    public function testBillingMail()
    {
        $paymentsAPI = $this->payment->buildBodyPaymentsAPI();
        $ordersAPI = $this->payment->buildBodyOrdersAPI();

        $this->assertEquals('dev@mollie.local', $paymentsAPI['billingEmail']);
        $this->assertEquals(false, isset($ordersAPI['payments']['billingEmail']));
    }

    /**
     * This test verifies that we can set a custom expiration date
     * for our Orders API request.
     */
    public function testExpirationDate()
    {
        $dueInDays = 5;
        $expectedDueDate = date('Y-m-d', strtotime(' + ' . $dueInDays . ' day'));

        $this->payment->setExpirationDays($dueInDays);
        $request = $this->payment->buildBodyOrdersAPI();

        $this->assertEquals($expectedDueDate, $request['expiresAt']);
    }

}
