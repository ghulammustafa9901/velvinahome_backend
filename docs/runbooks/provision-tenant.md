# Runbook: provision a tenant (draft — finalised in Phase 8)

## 1. Company

```bash
php artisan company:create velvina-group "Velvina Group" --email=ops@example.com
```

A company is a landlord record only. Skip if the company already exists.

## 2. Brand

```bash
php artisan tenant:create velvina-group velvina-home "Velvina Home" \
  --storefront=velvinahome.com --admin=admin.velvinahome.com --api=api.velvinahome.com \
  --owner-name="Owner Name" --owner-email=owner@velvinahome.com --plan=standard
```

The slug names the database (`tenant_velvina_home`) and its two roles
(`tenant_velvina_home_app`, `tenant_velvina_home_migrator`). It cannot change later.

Steps, each recorded as it completes: database and roles → migrations and ledger lockdown →
seed defaults → search collections (Phase 2) → storage prefix → owner account → activate.

**If it fails:** fix the cause and run the *same command again*. Completed steps are skipped;
nothing is duplicated. The tenant stays `provisioning` (and unreachable) until every step
succeeds.

The owner's temporary password is printed **once**. Deliver it through a secure channel; the
owner must enrol an authenticator app at first sign-in.

The same flow is available in the platform panel (Brands → Provision brand).

## 3. Multi-brand owners

Creating a second brand of the same company with the same owner email links both brands to
one owner identity automatically; the brand switcher then appears in both admins.

## 4. Go-live

Follow the checklist `tenant:create` prints, and [tenant-onboarding.md](../tenant-onboarding.md).
