# API policy

Phase 0 has no API route file, REST/GraphQL endpoints or frontend fetch layer. Laravel web controllers render Inertia React pages; mutations use web routes with session/CSRF, validation and server authorization.

Future mobile, external integrations, headless commerce or third-party applications may justify an API adapter. Reuse core operations, never call an admin controller internally. Before implementation decide authentication/scopes, versioning, projections, idempotency, rate limits and compatibility. Inertia protocol responses are not a public integration API.
