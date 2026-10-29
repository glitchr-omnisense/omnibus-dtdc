<?php

namespace Omnibus\Dtdc\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Dtdc\Api;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/** POST /consignment/softdata: the consignment (service: B2C Priority by default, PTP for Priority, GROUND...), then its label from /consignment/label. */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $data = $this->api->call('POST', '/consignment/softdata', ['consignments' => [array_filter([
            'customer_code' => $this->api->customerCode,
            'service_type_id' => $s->service ?? 'B2C PRIORITY',
            'load_type' => 'NON-DOCUMENT',
            'description' => (string) $s->option('description', 'Merchandise'),
            'dimension_unit' => 'cm',
            'length' => (string) ($s->parcels[0]->length ?? 10), 'width' => (string) ($s->parcels[0]->width ?? 10), 'height' => (string) ($s->parcels[0]->height ?? 10),
            'weight_unit' => 'kg',
            'weight' => (string) round(max(0.1, $s->weight() / 1000), 2),
            'declared_value' => (string) (array_sum(array_map(static fn ($p) => $p->value ?? 0, $s->parcels)) / 100),
            'num_pieces' => (string) \count($s->parcels),
            'customer_reference_number' => $s->reference,
            'origin_details' => self::party($s->sender),
            'destination_details' => self::party($s->recipient),
            'return_details' => self::party($s->sender),
            'cod_collection_mode' => '',
            'cod_amount' => '',
            'commodity_id' => (string) $s->option('commodity', 'Others'),
            'reference_number' => '',
            'is_risk_surcharge_applicable' => false,
        ], static fn ($v) => null !== $v)]]);
        $result = $data['data'][0] ?? [];
        if (!empty($result['success']) && false === $result['success'] || isset($result['success']) && !$result['success']) {
            throw new CarrierException('dtdc', (string) ($result['message'] ?? $result['reason'] ?? 'DTDC refused the consignment.'));
        }
        $number = (string) ($result['reference_number'] ?? '');
        if ('' === $number) {
            throw new CarrierException('dtdc', (string) ($result['message'] ?? 'DTDC issued no consignment number.'));
        }
        $content = null;
        try {
            $content = GetSlipAction::fetch($this->api, $number, (string) $s->option('label_code', 'SHIP_LABEL_4X6'));
        } catch (CarrierException) {
            // The consignment is booked; slip() fetches its label again.
        }
        $request->setResult(new Label('dtdc', $number, $content, Label::PDF, null, self::trackingUrl($number)));
    }

    public static function trackingUrl(string $number): string
    {
        return 'https://www.dtdc.in/trace.asp?strCnno='.rawurlencode($number);
    }

    private static function party(Address $a): array
    {
        return array_filter(['name' => mb_substr($a->company ?? $a->name, 0, 50), 'phone' => preg_replace('/\D+/', '', (string) $a->phone), 'alternate_phone' => '', 'address_line_1' => mb_substr($a->line(0), 0, 100), 'address_line_2' => mb_substr($a->line(1), 0, 100), 'pincode' => $a->postcode, 'city' => $a->city, 'state' => '', 'email' => (string) $a->email], static fn ($v) => null !== $v);
    }
}
