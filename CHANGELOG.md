# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the api-key, the tracking password (in the login's query)
  and the tracking token. The message quoted the HTTP client's (the whole URL), the previous was the
  client's own exception, and every trace of the chain kept the arguments of the calls under it (the
  request's options, the `Api` with its credentials) when `zend.exception_ignore_args` is off: a log
  of the whole chain wrote them. The message masks them as `***`, the previous is a copy of the
  client's exception (the same class and code), and no exception `Api` throws keeps its trace's
  arguments (`Omnibus\Http\Redaction`).
- A consignment not delivered is read `EXCEPTION`, not `DELIVERED`: an event with code `NDL` or
  `UND`, or the words "not delivered" or "undelivered", was caught by the "delivered" row first.
  DTDC's code now decides first (`DLV`, `NDL`/`UND`, `OFD`, `RTO`, `BKD`), then the words, the
  failed deliveries before "delivered".
- A label that failed to come at booking can be fetched again: `slip()` (GetSlip) asks
  `GET /consignment/label` for the consignment's number, as ship() does; a comment claimed it
  before, while GetSlip was not supported.
