# ADR-0013: Company owner identity without credentials; hand-off tokens

- **Status:** Accepted
- **Date:** 2026-09-26
- **Spec reference:** §4.1a (DEFAULT: multi-brand owner access), §4.9 (FINAL: impersonation)

## Context

`data-model.md` §1.6 gave `company_owner_identities` a password hash and 2FA secret, i.e.
a second login system in the landlord database. The spec only requires that an owner can
reach every brand of their company "from one identity, with a brand switcher", permissions
staying per brand.

## Decision

- The landlord identity holds **no credentials** — name, email, company, status only. The
  owner signs in to any one brand's admin with that brand's own staff account (and its
  mandatory 2FA).
- `company_owner_brand_links` maps the identity to the owner's staff ULID in each brand.
- The **brand switcher** issues a 60-second, single-use token (only its SHA-256 is stored)
  after checking both links in the landlord. The token is redeemed on the *target* brand's
  own admin host, which starts a fresh session there. No session and no query crosses
  databases.
- **Platform support access** uses the same hand-off mechanism: a mandatory reason
  (≥ 10 characters), a single-use token bound to one tenant, a time-limited session enforced
  by middleware, a landlord `impersonation_sessions` row and a mirrored entry in the brand's
  own `activity_log`.
- Anything invalid — forged, replayed, expired, or presented on another brand — answers 404.

## Consequences

### Positive

- One authentication system per brand; 2FA is never bypassed by a landlord login.
- The landlord database holds no password for anyone but platform users.

### Negative / costs

- An owner must sign in once to *some* brand before switching; there is no company-level
  login page. Acceptable for a managed-service platform.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Landlord login with credentials (the original data-model draft) | Duplicates authentication, 2FA and lockout in a second place for no benefit. |
| Shared session across brand domains | Cookies cannot be shared across unrelated domains, and would cross the isolation boundary. |
