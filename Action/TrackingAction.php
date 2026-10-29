<?php

namespace Omnibus\Dtdc\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Dtdc\Api;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;

/** The tracking API's getTrackDetails: the consignment's actions, oldest first. */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $data = $this->api->track($request->trackingNumber);
        $events = [];
        foreach ($data['trackDetails'] ?? [] as $d) {
            $at = \DateTimeImmutable::createFromFormat('dmY Hi', ($d['strActionDate'] ?? '01011970').' '.($d['strActionTime'] ?? '0000')) ?: new \DateTimeImmutable();
            $events[] = new TrackingEvent($at, self::status($d['strCode'] ?? null, $d['strAction'] ?? null), (string) ($d['strAction'] ?? ''), $d['strOrigin'] ?? null, $d['strCode'] ?? null);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        $status = self::status($data['trackHeader']['strStatus'] ?? null, $data['trackHeader']['strStatus'] ?? null);
        if (TrackingStatus::UNKNOWN === $status && $events) {
            $status = $events[array_key_last($events)]->status;
        }
        $request->setResult(new TrackingModel('dtdc', $request->trackingNumber, $status, $events));
    }

    /**
     * DTDC's code decides first; without a known code, the words, the negative ones ("not
     * delivered", "undelivered") before "delivered", which they contain.
     */
    private static function status(?string $code, ?string $action): TrackingStatus
    {
        $a = strtolower((string) $action);

        return match (true) {
            'DLV' === $code => TrackingStatus::DELIVERED,
            \in_array($code, ['NDL', 'UND'], true) => TrackingStatus::EXCEPTION,
            'OFD' === $code => TrackingStatus::OUT_FOR_DELIVERY,
            'RTO' === $code => TrackingStatus::RETURNED,
            'BKD' === $code => TrackingStatus::PENDING,
            str_contains($a, 'not delivered') || str_contains($a, 'undelivered') => TrackingStatus::EXCEPTION,
            str_contains($a, 'delivered') => TrackingStatus::DELIVERED,
            str_contains($a, 'out for delivery') => TrackingStatus::OUT_FOR_DELIVERY,
            str_contains($a, 'return') => TrackingStatus::RETURNED,
            str_contains($a, 'booked') || str_contains($a, 'softdata') => TrackingStatus::PENDING,
            null !== $code && '' !== $code || '' !== $a => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
