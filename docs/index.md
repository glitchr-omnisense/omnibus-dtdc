# omnibus/dtdc

DTDC for [omniscient/omnibus](https://github.com/glitchr-studio/omnibus): consignments with their
PDF labels and cancellation through DTDC's customer integration API (an `api-key`), tracking
through DTDC's tracking API (a login of its own). DTDC quotes by contract: prices come from the
`rates` option. The gateway calls:

| Operation | API | Request |
|---|---|---|
| ship | integration | `POST /consignment/softdata`, then `GET /consignment/label` |
| cancel | integration | `POST /consignment/cancel` |
| tracking token | tracking | `GET https://blktracksvc.dtdc.com/dtdc-api/api/dtdc/authenticate` |
| track | tracking | `POST https://blktracksvc.dtdc.com/dtdc-api/rest/JSONCnTrk/getTrackDetails` |

Integration paths are under `https://dtdcapi.shipsy.io/api/customer/integration` (live) or
`https://demodashboardapi.shipsy.in/api/customer/integration` (the demo environment,
`sandbox: true`); the tracking API has one address, whatever `sandbox` says. Every call has a
20-second timeout.

## Installation

```sh
composer require omnibus/dtdc
```

Requires PHP 8.2, `omniscient/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

A DTDC business account; DTDC's integration team gives (the demo environment first):

- the integration **api-key** and the **customer code**, for consignments and cancellations;
- the tracking API's **username** and **password**. Without them, the gateway does not support
  `Tracking`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the integration key, sent as `api-key` |
| `customer_code` | string | - | yes | the customer code |
| `tracking_username` | ?string | `null` | no | the tracking API's username |
| `tracking_password` | ?string | `null` | no | the tracking API's password |
| `sandbox` | bool | `false` | no | `true`: the demo integration environment |
| `rates` | list | - | no | the only way this gateway rates ([the rates option](https://github.com/glitchr-studio/omnibus/blob/2.x/docs/configuration.md#the-rates-option)) |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `description` | `Merchandise` | the content's description |
| `commodity` | `Others` | DTDC's commodity id |
| `label_code` | `SHIP_LABEL_4X6` | `SHIP_LABEL_4X6` or `SHIP_LABEL_A4` |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | only with `rates` (configured prices) |
| `Shipping` | yes |
| `Tracking` | only with `tracking_username` and `tracking_password` |
| `Pickup` | no: DTDC delivers to the door |
| `GetSlip` | no |
| `Cancel` | yes |
| `Paperless` | no |

## Behaviour

**Ship.** `POST /consignment/softdata` with one consignment:

- the customer code; the service type `Shipment::$service`, else `B2C PRIORITY` (DTDC's ids
  include `PTP` and `GROUND EXPRESS`); load type `NON-DOCUMENT`;
- the `description` and the `commodity`;
- the first parcel's dimensions in centimetres (a missing one counts as 10), the total weight in
  kilograms rounded to two decimals (at least 0.1), the declared value (the sum of the parcels'
  `value`), the number of parcels;
- the shipment's `reference` as customer reference number; DTDC assigns the consignment number;
- origin and return: the sender; destination: the recipient; each with the name (the company,
  else the person, 50 characters), the phone's digits, two address lines (100 characters), PIN
  code, city and email; the state is sent empty;
- no cash on delivery, no risk surcharge.

A consignment answered with `success` false raises its `message` (else `reason`). The tracking
number is the `reference_number` DTDC returns. Then `GET /consignment/label` for that number, the
`label_code` and the PDF format: the label returned (base64) is the label's `content`. When that
request fails, the booking still succeeds without content (a comment in `ShippingAction` says
`GetSlip` asks again; `GetSlip` is not supported). The format is `application/pdf`; `trackingUrl`
is `https://www.dtdc.in/trace.asp?strCnno=<number>`. `pickupPoint` is not sent.

**Track.** The first tracking call of a gateway asks for an access token with the tracking
username and password in the query string; the token is kept for the gateway's life. The
tracking request (`trkType cnno`, additional details) sends it as `X-Access-Token`. Each detail:
`strActionDate` and `strActionTime` read as `dmY Hi` (an unreadable date is dated now), the action
as description, the origin as location, the code as `code`; sorted oldest first. The status is read
from the header's `strStatus` text, else the latest event's. A status, in this order:

| Code | Or the text contains | `TrackingStatus` |
|---|---|---|
| `DLV` | `delivered` | `DELIVERED` |
| `OFD` | `out for delivery` | `OUT_FOR_DELIVERY` |
| `RTO` | `return` | `RETURNED` |
| `NDL`, `UND` | `not delivered`, `undelivered` | `EXCEPTION` |
| `BKD` | `booked`, `softdata` | `PENDING` |
| any other code, or any other text | | `IN_TRANSIT` |
| neither | | `UNKNOWN` |

"not delivered" and "undelivered" contain `delivered`, which the first row catches: such an event
reads as `DELIVERED`, whatever its code.

> [!WARNING]
> Known defect: "not delivered" and "undelivered" read `DELIVERED`, even with code `NDL` or `UND`, where they are an `EXCEPTION`.

**Cancel.** `POST /consignment/cancel` with the number and the customer code: `true` when no
number is listed as failed and the status is `OK` (or absent).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Dtdc\DtdcGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$dtdc = (new DtdcGatewayFactory(HttpClient::create()))->create([
    'api_key' => getenv('DTDC_API_KEY'),
    'customer_code' => getenv('DTDC_CUSTOMER'),
    'tracking_username' => getenv('DTDC_TRACK_USER'),       // leave both out: no tracking
    'tracking_password' => getenv('DTDC_TRACK_PASSWORD'),
    'sandbox' => true,
    'rates' => [
        ['service' => 'B2C PRIORITY', 'label' => 'DTDC Priority', 'currency' => 'INR', 'days' => 2,
            'countries' => ['IN'], 'bands' => [1000 => 9000, 5000 => 25000]],
    ],
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['1 Marine Drive'], '400020', 'Mumbai', 'IN', phone: '+91 22 1234 5678'),
    new Address('Ada Lovelace', ['1 MG Road'], '560001', 'Bengaluru', 'IN', email: 'ada@example.org', phone: '+91 80 1234 5678'),
    [new Parcel(1500, 30, 20, 10, value: 150000, currency: 'INR')],
    reference: 'CMD-1042',
    options: ['description' => 'Prints', 'label_code' => 'SHIP_LABEL_4X6'],
);

foreach ($dtdc->rate($shipment) as $rate) {
    printf("%s %s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $dtdc->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', (string) $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $dtdc->track($label->trackingNumber);
echo $tracking->status->value, "\n";

var_dump($dtdc->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        dtdc:
            factory: dtdc
            options:
                api_key: '%env(DTDC_API_KEY)%'
                customer_code: '%env(DTDC_CUSTOMER)%'
                tracking_username: '%env(DTDC_TRACK_USER)%'
                tracking_password: '%env(DTDC_TRACK_PASSWORD)%'
                sandbox: '%env(bool:DTDC_SANDBOX)%'
                rates:
                    - { service: 'B2C PRIORITY', label: 'DTDC Priority', currency: INR, bands: { 5000: 25000 } }
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `dtdc`:

- integration: an HTTP status of 400 or more, or a `status` of `ERROR`, `FAILED` or `false`: the
  answer's `error`, else `message`, else the first consignment's `message`, else `HTTP <status>`;
  its `errorCode` in `carrierCode`; a body that is not JSON: `DTDC answered HTTP <status> with a
  body that is not JSON.`; a network error: `DTDC request failed: ...`;
- a consignment refused: its `message` (else `reason`, else `DTDC refused the consignment.`); no
  number: its `message`, else `DTDC issued no consignment number.`;
- tracking: the login refused: `DTDC gave no tracking token (HTTP <status>): check the tracking
  username and password.`; an HTTP status of 400 or more: the first `errorDetails` value, else
  `message`, else `DTDC tracking answered HTTP <status>.`; a number DTDC does not know
  (`statusFlag` false, no header): its error detail, else `DTDC knows no such consignment.`; a
  network error or unreadable body: `DTDC tracking failed: ...`.

A failed label request after the consignment is booked is not raised (above). A missing `api_key`
or `customer_code` is an `InvalidConfigException` when the gateway is built; `Tracking` without
its login, `Pickup`, `GetSlip`, `Paperless`, and `Rating` without `rates`, raise
`RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/DtdcGatewayTest.php` (a consignment booked on the demo host with the
`api-key` header, the customer code, the service type and the PIN code, its label fetched by
reference number; tracking with its own token and the delivered status; the cancellation;
configured rates) and `Tests/DtdcContractTest.php`, the family's conformance suite on recorded
answers (`Tracking` supported only with its login). The example above was run in the core's
harness on those recorded answers.

Not verified: the README and the factory mark the package **unverified** against the demo
environment until an account's keys are at hand; it is built from DTDC's published integration
documentation.

## Responsibility

An independent, unofficial client, not affiliated with DTDC Express Limited. Whoever runs it
complies with DTDC's terms ([dtdc.in terms and
conditions](https://www.dtdc.in/terms-and-conditions.asp)): see the README's Responsibility
section.
