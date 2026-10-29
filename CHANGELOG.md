# Changelog

## [Unreleased]

### Fixed
- A consignment not delivered is read `EXCEPTION`, not `DELIVERED`: an event with code `NDL` or
  `UND`, or the words "not delivered" or "undelivered", was caught by the "delivered" row first.
  DTDC's code now decides first (`DLV`, `NDL`/`UND`, `OFD`, `RTO`, `BKD`), then the words, the
  failed deliveries before "delivered".
