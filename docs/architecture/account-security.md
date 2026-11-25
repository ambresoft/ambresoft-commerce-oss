# Account security history

Authorized November 11, 2026 for customer sign-in identities and CMS staff. Identity owns the recording infrastructure; Admin owns the review screens. The shared `users` identity is the subject, so a linked customer and staff account do not acquire conflicting histories.

## Recording

`identity_security_events` is an append-only history of registration, completed session starts (including remembered-cookie session restoration) and failed password checks for known accounts. It stores the request IP obtained through Laravel's trusted-proxy policy, browser/platform descriptions, a device identifier hash, optional country/city/IANA timezone, UTC timestamp, entry area and first-seen IP/device flags. It never stores passwords, submitted credentials, session IDs or the device cookie value. Existing accounts have no reconstructed registration origin. Staff-created customer profiles do not inherit their creator's IP. A staff invitation records origin when accepted; social registration records the callback browser's origin.

An encrypted, HttpOnly, SameSite=Lax `cms_security_device` cookie distinguishes browser installations for one year; Secure follows HTTPS. Issue it equally on known and unknown login attempts. The canonical request attributes share a newly generated ID across Laravel FormRequest copies during registration. The identifier combines the opaque browser ID with parsed browser/platform, excluding version changes. Cookies can be cleared or copied and user agents can be spoofed: this is an investigative signal, not proof of a physical device or a replacement for authentication. User-row locking serializes first-seen checks. Only successful sessions establish known IPs/devices; failed attempts cannot establish a trusted baseline.

Password failures are counted cumulatively and over the last 15 minutes. Five recent failures surface a review warning. Existing authentication throttles still apply; throttled requests are not counted as checked passwords. Unknown identities are not retained by this history and receive the same response/cookie behavior. New IP and browser flags are informational; they do not automatically block the account or send messages.

## Geolocation

`geoip2/geoip2` reads a local GeoLite2-City or GeoIP2-City MMDB file. Set `SECURITY_GEOIP_DATABASE`, or place it at `storage/app/private/GeoLite2-City.mmdb`. The operator obtains and updates the database under its provider's terms; no credentials or database files belong in Git. No login IP is sent to a geolocation API. Private/reserved IPs, absent/unreadable databases and lookup failures return unavailable location. Do not infer a customer's location from a store default or an address. `donatj/phpuseragentparser` supplies browser/platform descriptions.

Local time is a live estimate in the timezone associated with the last observed successful IP, including daylight-saving changes. It is not the customer's measured browser timezone. VPNs, proxies and mobile networks affect its accuracy.

## Administration and access

`account-security.view` is restricted to active, verified persisted super administrators, including when an ordinary role has a legacy or forged grant. It cannot be delegated through role editors or application role saves. Server controllers omit sensitive props for other staff; hiding a React component alone is never authorization.

Customer detail and the CMS user's Security tab expose the shared summary and link to `/admin/account-security/{user}`. The review screen has independently paginated event, IP and device histories plus the immutable registration origin. The main dashboard shows the latest ten security events with links and estimated local time, only to super admins. English and Greek copy uses the `account_security` dictionary.

The history is retained until the identity is deleted, at which point the foreign key cascades. No bulk deletion, export, external alerting or new session-revocation workflow is introduced. The existing audit log stays separate and does not receive copies of this sensitive payload. Configure a different retention policy explicitly before introducing pruning.

## Deployment and verification

Install Composer dependencies, run the additive security migration and `cms:permissions-sync`, then build the frontend. Without the optional local City database, IP, browser and password history work while location/local time display unavailable. `AccountSecurityTest` covers actual registration/login requests, cookie stability, independent IP/device flags, failures, inaccessible sensitive props, nondelegable authorization and missing/private-IP location. Existing authentication, social-sign-in and passkey tests exercise the shared session lifecycle. Clock tests cover live updates and invalid/unavailable timezones.
