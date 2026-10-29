<?php

namespace Omnibus\Dtdc;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DTDC's customer integration APIs (an api-key per customer for consignments
 * and cancellation; a separate access token for tracking, fetched with the
 * tracking username and password).
 */
final class Api
{
    public const LIVE = 'https://dtdcapi.shipsy.io/api/customer/integration';
    public const TEST = 'https://demodashboardapi.shipsy.in/api/customer/integration';
    public const TRACKING = 'https://blktracksvc.dtdc.com/dtdc-api/rest/JSONCnTrk/getTrackDetails';
    public const TRACKING_TOKEN = 'https://blktracksvc.dtdc.com/dtdc-api/api/dtdc/authenticate';

    private ?string $trackingToken = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        public readonly string $customerCode,
        public readonly ?string $trackingUsername = null,
        public readonly ?string $trackingPassword = null,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, ($this->sandbox ? self::TEST : self::LIVE).$path, [
                'headers' => ['api-key' => $this->apiKey, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('dtdc', 'DTDC request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('dtdc', sprintf('DTDC answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400 || (isset($data['status']) && \in_array($data['status'], ['ERROR', 'FAILED', false], true))) {
            throw Redaction::withoutArguments(new CarrierException('dtdc', (string) ($data['error'] ?? $data['message'] ?? $data['data'][0]['message'] ?? sprintf('HTTP %d', $status)), isset($data['errorCode']) ? (string) $data['errorCode'] : null));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function track(string $number): array
    {
        if (!$this->trackingUsername || !$this->trackingPassword) {
            throw Redaction::withoutArguments(new CarrierException('dtdc', 'Tracking needs the tracking API\'s username and password (options tracking_username, tracking_password).'));
        }
        try {
            if (null === $this->trackingToken) {
                $login = $this->http->request('GET', self::TRACKING_TOKEN, ['query' => ['username' => $this->trackingUsername, 'password' => $this->trackingPassword], 'timeout' => $this->timeout]);
                if ($login->getStatusCode() >= 400) {
                    throw Redaction::withoutArguments(new CarrierException('dtdc', sprintf('DTDC gave no tracking token (HTTP %d): check the tracking username and password.', $login->getStatusCode())));
                }
                $this->trackingToken = trim($login->getContent(false));
            }
            $response = $this->http->request('POST', self::TRACKING, ['headers' => ['X-Access-Token' => $this->trackingToken, 'Content-Type' => 'application/json'], 'body' => json_encode(['trkType' => 'cnno', 'strcnno' => $number, 'addtnlDtl' => 'Y']), 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('dtdc', 'DTDC tracking failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 400) {
            throw Redaction::withoutArguments(new CarrierException('dtdc', (string) ($data['errorDetails'][0]['value'] ?? $data['message'] ?? sprintf('DTDC tracking answered HTTP %d.', $status))));
        }
        if ('FALSE' === strtoupper((string) ($data['statusFlag'] ?? 'TRUE')) && empty($data['trackHeader'])) {
            throw Redaction::withoutArguments(new CarrierException('dtdc', (string) ($data['errorDetails'][0]['value'] ?? 'DTDC knows no such consignment.')));
        }

        return $data;
    }

    /**
     * What a failure must not carry: the credentials and the token, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->apiKey, (string) $this->trackingPassword, (string) $this->trackingToken, ...$more];
    }
}
