<?php

namespace Omnibus\Dtdc\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Dtdc\DtdcGatewayFactory;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on DTDC's recorded answers (unverified API shape); no rating API, so no rate without the "rates" option. */
final class DtdcContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new DtdcGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['api_key' => 'key', 'customer_code' => 'GL001', 'sandbox' => true, 'tracking_username' => 'tu', 'tracking_password' => 'tp'];
    }

    protected function operationOptions(): array
    {
        return ['tracking_username' => [\Omnibus\Request\Tracking::class], 'tracking_password' => [\Omnibus\Request\Tracking::class]];
    }

    protected function sessionRoutes(): array
    {
        return ['/authenticate'];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['Nariman Point'], '400021', 'Mumbai', 'IN', phone: '02212345678'), new Address('Alex Martin', ['MG Road'], '560001', 'Bengaluru', 'IN', phone: '9876543210'), [new Parcel(1500, 30, 20, 10, 150000, 'INR')], reference: 'ORDER-1042');
    }

    protected function contractTrackingNumber(): string
    {
        return 'D12345678';
    }

    protected function recordedAnswers(): array
    {
        return [
            '/consignment/softdata' => ['status' => 'OK', 'data' => [['success' => true, 'reference_number' => 'D12345678', 'customer_reference_number' => 'ORDER-1042']]],
            '/consignment/label' => ['status' => 'OK', 'data' => [['label' => base64_encode('%PDF-1.4 dtdc')]]],
            '/consignment/cancel' => ['status' => 'OK', 'successAWBNo' => ['D12345678'], 'failedAWBNo' => []],
            '/authenticate' => 'tracking-token',
            '/getTrackDetails' => ['statusFlag' => true, 'trackHeader' => ['strShipmentNo' => 'D12345678', 'strStatus' => 'Delivered'], 'trackDetails' => [['strCode' => 'DLV', 'strAction' => 'Delivered', 'strActionDate' => '02102026', 'strActionTime' => '1130', 'strOrigin' => 'Bengaluru'], ['strCode' => 'BKD', 'strAction' => 'Booked', 'strActionDate' => '01102026', 'strActionTime' => '1700', 'strOrigin' => 'Mumbai']]],
        ];
    }
}
