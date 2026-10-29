<?php

namespace Omnibus\Dtdc\Tests;

use Omnibus\Dtdc\DtdcGatewayFactory;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DtdcGatewayTest extends TestCase
{
    private array $calls = [];

    private ?array $trackDetails = null;

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['Nariman Point'], '400021', 'Mumbai', 'IN', phone: '02212345678'), new Address('Alex Martin', ['MG Road'], '560001', 'Bengaluru', 'IN', phone: '9876543210'), [new Parcel(1500, 30, 20, 10, 150000, 'INR')], reference: 'ORDER-1042');
    }

    private function gateway(bool $tracking = true): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $url, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : [], $options['headers']];

            return match (true) {
                str_ends_with($path, '/consignment/softdata') => new MockResponse(json_encode(['status' => 'OK', 'data' => [['success' => true, 'reference_number' => 'D12345678', 'customer_reference_number' => 'ORDER-1042']]])),
                str_ends_with($path, '/consignment/label') => new MockResponse(json_encode(['status' => 'OK', 'data' => [['label' => base64_encode('%PDF-1.4 dtdc')]]])),
                str_ends_with($path, '/consignment/cancel') => new MockResponse(json_encode(['status' => 'OK', 'successAWBNo' => ['D12345678'], 'failedAWBNo' => []])),
                str_ends_with($path, '/authenticate') => new MockResponse('tracking-token'),
                str_ends_with($path, '/getTrackDetails') => new MockResponse(json_encode($this->trackDetails ?? ['statusFlag' => true, 'trackHeader' => ['strShipmentNo' => 'D12345678', 'strStatus' => 'Delivered'], 'trackDetails' => [['strCode' => 'DLV', 'strAction' => 'Delivered', 'strActionDate' => '02102026', 'strActionTime' => '1130', 'strOrigin' => 'Bengaluru'], ['strCode' => 'BKD', 'strAction' => 'Booked', 'strActionDate' => '01102026', 'strActionTime' => '1700', 'strOrigin' => 'Mumbai']]])),
                default => new MockResponse(json_encode(['status' => 'ERROR', 'error' => 'No such resource']), ['http_code' => 404]),
            };
        });

        return (new DtdcGatewayFactory($http))->create(['api_key' => 'key', 'customer_code' => 'GL001', 'sandbox' => true, 'rates' => [['service' => 'B2C PRIORITY', 'label' => 'DTDC Priority', 'currency' => 'INR', 'bands' => [5000 => 25000]]]] + ($tracking ? ['tracking_username' => 'tu', 'tracking_password' => 'tp'] : []));
    }

    public function testAConsignmentIsBookedWithItsLabel(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('D12345678', $label->trackingNumber);
        self::assertSame('%PDF-1.4 dtdc', $label->content);
        self::assertStringStartsWith('https://demodashboardapi.shipsy.in', $this->calls[0][1]);
        self::assertContains('api-key: key', $this->calls[0][3]);
        $sent = $this->calls[0][2]['consignments'][0];
        self::assertSame('GL001', $sent['customer_code']);
        self::assertSame('B2C PRIORITY', $sent['service_type_id']);
        self::assertSame('560001', $sent['destination_details']['pincode']);
        self::assertStringContainsString('reference_number=D12345678', $this->calls[1][1]);
    }

    public function testTrackingNeedsItsOwnLoginAndCancelWorks(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('D12345678');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Booked', $tracking->events[0]->description);
        self::assertContains('X-Access-Token: tracking-token', $this->calls[1][3]);
        self::assertTrue($gateway->cancel('D12345678'));
        self::assertSame(25000, $gateway->rate(self::shipment())[0]->amount);

        $this->expectException(\Omnibus\Exception\RequestNotSupportedException::class); // without its option, not offered
        $this->gateway(false)->track('D12345678');
    }

    /**
     * A consignment DTDC could not deliver: code NDL or UND, words "Not Delivered" or
     * "Undelivered" (DTDC's tracking API, getTrackDetails' strCode and strAction), an exception.
     */
    public function testANotDeliveredConsignmentIsAnException(): void
    {
        $this->trackDetails = ['statusFlag' => true, 'trackHeader' => ['strShipmentNo' => 'D12345678', 'strStatus' => 'Not Delivered'], 'trackDetails' => [
            ['strCode' => 'UND', 'strAction' => 'Undelivered - Address incomplete', 'strActionDate' => '03102026', 'strActionTime' => '1015', 'strOrigin' => 'Bengaluru'],
            ['strCode' => 'NDL', 'strAction' => 'Not Delivered - Consignee not available', 'strActionDate' => '02102026', 'strActionTime' => '1130', 'strOrigin' => 'Bengaluru'],
            ['strCode' => 'BKD', 'strAction' => 'Booked', 'strActionDate' => '01102026', 'strActionTime' => '1700', 'strOrigin' => 'Mumbai'],
        ]];
        $tracking = $this->gateway()->track('D12345678');
        self::assertSame(TrackingStatus::EXCEPTION, $tracking->status);
        self::assertSame(TrackingStatus::EXCEPTION, $tracking->events[1]->status);
        self::assertSame(TrackingStatus::EXCEPTION, $tracking->events[2]->status);
        self::assertSame(TrackingStatus::PENDING, $tracking->events[0]->status);
    }

    /** DTDC's label is fetched by the consignment's reference number (GET /consignment/label): slip() fetches it again, as ship() does. */
    public function testALabelIsFetchedAgain(): void
    {
        $label = $this->gateway()->slip('D12345678');
        self::assertSame('D12345678', $label->trackingNumber);
        self::assertSame('%PDF-1.4 dtdc', $label->content);
        self::assertStringContainsString('reference_number=D12345678', end($this->calls)[1]);
    }
}
