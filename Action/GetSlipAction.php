<?php

namespace Omnibus\Dtdc\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Dtdc\Api;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\GetSlip;
use Omnibus\Request\Request;

/**
 * GET /consignment/label by reference number: the label of a consignment booked earlier, as
 * ship() fetches it (label code SHIP_LABEL_4X6, PDF: DTDC prints no other format).
 */
final class GetSlipAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetSlip;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetSlip);
        $content = self::fetch($this->api, $request->trackingNumber, 'SHIP_LABEL_4X6');
        if (null === $content) {
            throw new CarrierException('dtdc', sprintf('DTDC gave no label for %s.', $request->trackingNumber));
        }
        $request->setResult(new Label('dtdc', $request->trackingNumber, $content, Label::PDF, null, ShippingAction::trackingUrl($request->trackingNumber)));
    }

    /** The consignment's label, base64-decoded, or null when DTDC sends none. */
    public static function fetch(Api $api, string $number, string $labelCode): ?string
    {
        $label = $api->call('GET', '/consignment/label', null, ['reference_number' => $number, 'label_code' => $labelCode, 'label_format' => 'pdf']);
        $encoded = $label['data'][0]['label'] ?? $label['data'] ?? $label['label'] ?? null;

        return \is_string($encoded) && '' !== $encoded ? (base64_decode($encoded, true) ?: null) : null;
    }
}
