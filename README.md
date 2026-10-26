# omnibus/dtdc

DTDC for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): consignments with their
labels and cancellation through DTDC's customer integration API (an api-key), tracking through
DTDC's tracking API (its own login). Prices come from configuration (`rates`): DTDC quotes by
contract.

```php
$gateway = (new DtdcGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

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
                sandbox: true
                rates:
                    - { service: 'B2C PRIORITY', label: 'DTDC Priority', currency: INR, bands: { 5000: 25000 } }
```

The service is DTDC's service type id (B2C PRIORITY by default, PTP, GROUND EXPRESS...). Shipment
options: `description`, `commodity`, `label_code` (SHIP_LABEL_4X6, SHIP_LABEL_A4). No pickup
points: DTDC delivers to the door.

Credentials: a DTDC business account; the integration api-key and customer code, and the tracking
login, come from DTDC's integration team (the demo environment first).

Built from DTDC's published integration documentation and tested on recorded answers;
**unverified** against the demo environment until an account's keys are at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by DTDC Express Limited. It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with DTDC's terms ([dtdc.in
terms and conditions](https://www.dtdc.in/terms-and-conditions.asp)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
