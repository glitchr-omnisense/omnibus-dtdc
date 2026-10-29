# Changelog

## [Unreleased]

### Fixed
- A consignment not delivered is read `EXCEPTION`, not `DELIVERED`: an event with code `NDL` or
  `UND`, or the words "not delivered" or "undelivered", was caught by the "delivered" row first.
  DTDC's code now decides first (`DLV`, `NDL`/`UND`, `OFD`, `RTO`, `BKD`), then the words, the
  failed deliveries before "delivered".
- A label that failed to come at booking can be fetched again: `slip()` (GetSlip) asks
  `GET /consignment/label` for the consignment's number, as ship() does; a comment claimed it
  before, while GetSlip was not supported.
