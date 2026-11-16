# Core 1.2.100 scheduler candidate knowledge provenance

The unpublished candidate adds `run-scheduled-tasks` to invoke the existing Core scheduler from site cron. The command boots enabled plugin handlers and holds a site-level process lock. It adds no migration or public plugin API. Sites must configure their own cron after a future Core upgrade; this candidate has not been deployed to production websites.

The V1 knowledge package is repinned to the reviewed candidate source commit `b75c7a8ee4daa7fa50cc4151f9c2e4ba09193464` and version 1.2.100. Published Core 1.2.99 release evidence remains in `release_evidence`. Its 59 existing facts keep their visibility and customer-service eligibility; the new operational command is documented in `docs/foundation-services.md` rather than being offered as customer guidance. This source review does not import or index knowledge for a consumer.

The candidate's scheduler CLI and concurrency tests passed on PHP 8.3 and 8.5 on the authorized update server. Full release, signing, native upgrade and publication checks remain separate gates.
