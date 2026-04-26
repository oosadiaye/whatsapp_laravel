# Meta WhatsApp Cloud API — Customer Onboarding

Each BlastIQ customer connects their own Meta Business account. This walkthrough covers the one-time setup. Allow 30-60 minutes plus Meta's verification time (usually a few hours, occasionally days).

---

## Prerequisites checklist

Before you start, gather:

- [ ] A Facebook personal account (used to log into Business Manager)
- [ ] A business name + legal entity (the same one Meta will display on WhatsApp messages)
- [ ] A phone number that **isn't currently registered with WhatsApp** (consumer or Business app). If it is, you'll need to delete the WhatsApp account on that number first via the consumer app.
- [ ] A way to receive SMS or voice calls on that number for verification
- [ ] A working email address that receives admin notifications
- [ ] Your BlastIQ instance running on a **publicly reachable HTTPS URL** (Meta won't accept HTTP webhooks)

---

## Step 1 — Create a Meta Business account

1. Go to [business.facebook.com](https://business.facebook.com) and sign in with your Facebook account
2. Click **Create Account** if you don't already have a business
3. Provide your business name + your name + work email
4. Confirm via the verification email Meta sends

**Done when:** you can see the Meta Business Suite dashboard with your business name in the top-left.

---

## Step 2 — Create a Meta App

1. Go to [developers.facebook.com/apps](https://developers.facebook.com/apps)
2. Click **Create App**
3. Choose **Business** as the use case
4. Name your app (e.g. *"BlastIQ — Acme Corp"*)
5. Link it to the Business account you just created
6. Click **Create App**

**Done when:** you land on the App Dashboard at `developers.facebook.com/apps/<your-app-id>`

---

## Step 3 — Add WhatsApp to your app

1. From the App Dashboard, scroll to **Add a product** → find **WhatsApp** → click **Set Up**
2. You'll land on **WhatsApp → API Setup**
3. Meta provides a free **test phone number** automatically. **Don't use this for production** — it's rate-limited and shows a "test" prefix to recipients. You'll add your real number in Step 5.

**On this page, copy these two values into a temporary text file** — you'll paste them into BlastIQ shortly:

| Field | What to copy | Where in BlastIQ |
|---|---|---|
| **Temporary access token** | The 60-char string starting with `EAA...` | "Access Token" field (will be replaced with permanent token in Step 6) |
| **Phone number ID** | The 15-digit numeric ID below the test number | "Phone Number ID" field |
| **WhatsApp Business Account ID** | Click the dropdown above the access token; the WABA ID appears (15-16 digits) | "WhatsApp Business Account ID" field |

---

## Step 4 — Grab your App Secret

1. Top-left of the App Dashboard → **App settings** → **Basic**
2. Under **App Secret**, click **Show**
3. Copy the 32-char hex string

**Paste into BlastIQ:** "App Secret" field. This is used to verify webhook signatures (HMAC-SHA256) so unauthorized parties can't POST fake delivery receipts.

---

## Step 5 — Add your real business phone number

The test number won't do for actual customer messaging. Add a real one:

1. **WhatsApp → API Setup** → **Add phone number**
2. Choose **Use my own phone number**
3. Enter:
   - Phone number (E.164 format, e.g. `+12025551212`)
   - Verified business display name (the name customers will see in chat)
   - Time zone, category, description
4. Submit for review

**Verification options:**
- **SMS code** — works for most numbers
- **Voice call** — for numbers that don't receive SMS (toll-free, VoIP)

Enter the 6-digit code Meta sends. The number is now registered.

**Phone Number ID changes after this step** — go back to API Setup and copy the new Phone Number ID (the test one is now retired). Update the value in BlastIQ.

---

## Step 6 — Generate a permanent System User access token

The temporary token from Step 3 expires in 24 hours. For production, you need a permanent one tied to a System User.

### 6a. Create a System User

1. Go to [business.facebook.com/settings](https://business.facebook.com/settings)
2. Left sidebar → **Users** → **System Users** → **Add**
3. Name it `BlastIQ-Send` and choose role **Admin**
4. Click **Create**

### 6b. Add WhatsApp permissions

1. Click the System User you just created
2. **Add Assets** → search for your WhatsApp Business Account → tick **Manage**
3. Confirm

### 6c. Generate the token

1. Click **Generate New Token** on the System User
2. Select your Meta App from the dropdown
3. Token expiry: **Never** (this is the whole point — permanent token)
4. Permissions: tick `whatsapp_business_messaging` AND `whatsapp_business_management`
5. **Generate Token** → copy the long token (starts with `EAA`)

**Paste into BlastIQ:** "Access Token" field, replacing the temporary token. Save.

> ⚠️ **You won't see this token again.** Meta only shows it once. If you lose it, you'll have to regenerate from the System User panel.

---

## Step 7 — Save in BlastIQ + auto-verify

1. In BlastIQ, go to **Instances → Connect WhatsApp Cloud API Instance**
2. Fill in:
   - **Internal Name** — anything you like, e.g. `acme_main`
   - **Display Name** — leave blank, will auto-fill from Meta's verified name
   - **WhatsApp Business Account ID** — from Step 3
   - **Phone Number ID** — from Step 5 (post-verification)
   - **Access Token** — from Step 6c
   - **App Secret** — from Step 4
   - **Webhook Verify Token** — leave blank (auto-generated)
3. Click **Connect & Verify**

BlastIQ will hit `GET /{phone_number_id}` against Meta to validate the credentials. Three possible outcomes:

| Status | Meaning |
|---|---|
| `CONNECTED` | All good — Meta accepted credentials, phone metadata pulled |
| `CREDENTIALS_INVALID` | Meta rejected the token — re-check token + permissions |
| `UNREACHABLE` | Couldn't reach `graph.facebook.com` from BlastIQ — check server connectivity |

---

## Step 8 — Configure the webhook in Meta

This is what tells Meta where to POST delivery receipts.

1. On BlastIQ's Instance show page, you'll see **Webhook configuration** with two values:
   - **Callback URL** (e.g. `https://blast.dpluxtech.com/webhooks/whatsapp/3`)
   - **Verify Token** (32-char auto-generated string)
   - Both have a "Copy" button — copy both somewhere
2. Switch to Meta App Dashboard → **WhatsApp → Configuration**
3. Under **Webhook**, click **Edit**
4. Paste the **Callback URL** and **Verify Token**
5. Click **Verify and Save** — Meta calls your URL to verify the token. Should succeed instantly. If not, your webhook URL isn't reachable or the verify token doesn't match.
6. Once verified, scroll to **Webhook fields** → click **Manage** → tick at minimum:
   - `messages` (delivery + read receipts; required)
7. **Save**

---

## Step 9 — Submit your first template

Marketing campaigns require Meta-approved templates. Submit one:

### Option A: From Meta Business Manager directly (recommended for first setup)

1. [business.facebook.com](https://business.facebook.com) → **More tools** → **WhatsApp Manager**
2. Left sidebar → **Message Templates** → **Create Template**
3. Choose category: **Marketing**, **Utility**, or **Authentication**
4. Provide name (lowercase, underscores: `welcome_promo_v1`)
5. Pick language (e.g. `en_US`)
6. Write the BODY component with `{{1}}`, `{{2}}` placeholders for variables
7. Submit — Meta reviews in a few minutes to a few hours

### Option B: From BlastIQ (creates a local template, then submits)

1. Templates → Create Template → fill in name/content/category
2. Then on the Templates list → **Submit to Meta** → pick the instance
3. Wait. The 15-minute scheduled sync (`templates:sync-status`) will eventually flip status from PENDING → APPROVED. Or click **Sync from WhatsApp** manually to refresh.

---

## Step 10 — Sync templates and run a campaign

1. Templates → **Sync from WhatsApp** → pick your instance → templates appear with status badges
2. Contacts → import your contact list (CSV or manual entry)
3. Groups → create a group, add contacts
4. Campaigns → Create Campaign → pick template + group + schedule → launch

Watch delivery come in real-time on the campaign show page (Livewire poll every 3 seconds).

---

## Common issues

| Symptom | Likely cause | Fix |
|---|---|---|
| `(#190) Invalid OAuth access token` | Using the temp token from Step 3 | Re-do Step 6, paste the permanent System User token |
| `(#100) The parameter category is required` | Old API version | We're pinned to v20.0; this shouldn't happen — file an issue |
| Webhook verification fails in Meta dashboard | Verify token mismatch OR webhook URL not HTTPS | Re-copy verify token from BlastIQ instance page; ensure URL is HTTPS |
| Templates synced but `Submit to Meta` errors | App lacks `whatsapp_business_management` permission | Re-do Step 6c with both permissions ticked |
| Campaign launches but every send is FAILED | Template still PENDING, or contacts haven't messaged you in 24h | Wait for APPROVED, OR ensure all contacts are in the 24h conversation window |
| `body must be a non-empty string` | Template body is empty in our DB | Re-sync; this happens when Meta returns components without a BODY (rare) |

---

## When in doubt

Meta's own docs are excellent and stay current:
- [Cloud API getting started](https://developers.facebook.com/docs/whatsapp/cloud-api/get-started)
- [Pricing per country](https://developers.facebook.com/docs/whatsapp/pricing)
- [Template policy](https://developers.facebook.com/docs/whatsapp/message-templates/guidelines)
- [Webhook payloads](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples)
