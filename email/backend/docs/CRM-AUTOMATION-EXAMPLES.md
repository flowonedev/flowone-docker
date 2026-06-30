# CRM Automation Examples

10 real-world automation setups, from simple single-rule triggers to full lifecycle engines.

---

## Available Triggers

| Trigger | Config | Description |
|---------|--------|-------------|
| `deal_won` | -- | Fires when a deal is marked as Won |
| `deal_lost` | -- | Fires when a deal is marked as Lost |
| `deal_stage_changed` | `to_stage` | Fires when a deal moves to a specific stage |
| `deal_stage_idle` | `stage`, `days` | Fires when a deal sits in a stage for X days |
| `client_health_low` | `threshold` (0-100) | Fires when client health score drops below threshold |
| `invoice_overdue` | `days` | Fires when an invoice is overdue by X days |
| `no_contact_days` | `days` | Fires when no interaction with a client for X days |

## Available Actions

| Action | Config | Description |
|--------|--------|-------------|
| `send_email` | `subject`, `body` | Send an automated email to the client |
| `create_reminder` | `title`, `description` | Create a CRM reminder for yourself |
| `notify_user` | `message` | Internal notification alert |
| `move_deal_stage` | `to_stage` | Move a deal to a different pipeline stage |
| `create_invoice_draft` | `amount`, `description` | Auto-create an invoice draft |
| `start_sequence` | `sequence_id` | Enroll the client in a multi-step email sequence |

## Template Variables (for email body & subject)

| Variable | Replaced With |
|----------|---------------|
| `{client_name}` | Client's full name |
| `{deal_title}` | Deal title |
| `{deal_value}` | Deal monetary value |
| `{deal_stage}` | Current pipeline stage |
| `{invoice_number}` | Invoice number |
| `{company}` | Client's company/domain |
| `{step_number}` | Current sequence step number |

---

## 1. Basic Reminder -- Follow Up on Won Deals

**Complexity:** Minimal

| | |
|---|---|
| **Trigger** | `deal_won` |
| **Action** | `create_reminder` |
| **Reminder title** | "Send thank-you gift to client" |

When you close a deal as Won, a reminder pops up telling you to send a thank-you email or gift. No config needed, just fire and forget.

---

## 2. Lost Deal Notification

**Complexity:** Minimal

| | |
|---|---|
| **Trigger** | `deal_lost` |
| **Action** | `notify_user` |
| **Message** | "Deal lost -- review what went wrong" |

Every time a deal is marked Lost, you get an internal notification to review the loss reason. Good for keeping a habit of post-mortem analysis.

---

## 3. Overdue Invoice Nudge

**Complexity:** Simple

| | |
|---|---|
| **Trigger** | `invoice_overdue` (threshold: 7 days) |
| **Action** | `send_email` |
| **Subject** | "Friendly reminder: Invoice #{invoice_number}" |
| **Body** | "Hi {client_name}, just a quick reminder that invoice #{invoice_number} is now past due. Please let us know if you have any questions." |

7 days past due date, the system automatically emails the client a polite payment reminder. Runs every cron cycle, so you never have to manually chase invoices.

---

## 4. Stale Lead Auto-Reminder

**Complexity:** Simple

| | |
|---|---|
| **Trigger** | `deal_stage_idle` (stage: `lead`, days: 14) |
| **Action** | `create_reminder` |
| **Reminder title** | "This lead has been sitting for 2 weeks -- qualify or discard" |

If a deal stays in the "lead" stage for 14 days without any update, a reminder is created to push you to take action.

---

## 5. Silent Client Re-engagement Email

**Complexity:** Moderate

| | |
|---|---|
| **Trigger** | `client_health_low` (threshold: 40) |
| **Action** | `send_email` |
| **Subject** | "Hey {client_name}, it's been a while!" |
| **Body** | "Hi {client_name}, we noticed it's been a little quiet and wanted to check in. Is there anything we can help with? We'd love to catch up." |

Clients whose health score drops below 40 (no interaction in 30-60 days) automatically receive a warm check-in email. You don't have to track who's been quiet -- the system does it.

### How Health Score Works

| Last Activity | Score | Risk Level |
|---|---|---|
| Within 7 days | 100 | Healthy |
| Within 14 days | 80 | Healthy |
| Within 30 days | 60 | Moderate |
| Within 60 days | 40 | At Risk |
| Within 90 days | 20 | Critical |
| Over 90 days | 10 | Critical |

---

## 6. Auto-Move Stale Proposals to Negotiation

**Complexity:** Moderate

| | |
|---|---|
| **Trigger** | `deal_stage_idle` (stage: `proposal`, days: 21) |
| **Action** | `move_deal_stage` (to: `negotiation`) |

If a proposal sits untouched for 3 weeks, the deal automatically moves to the negotiation stage. This keeps your pipeline honest -- stale proposals don't pretend to be active.

---

## 7. Won Deal Triggers Onboarding Sequence

**Complexity:** Advanced

| | |
|---|---|
| **Trigger** | `deal_won` |
| **Action** | `start_sequence` (sequence: "New Client Onboarding") |

**The sequence has 4 steps:**

| Step | Delay | Subject | Body |
|------|-------|---------|------|
| 1 | Day 0 | "Welcome aboard, {client_name}!" | Welcome email with project kickoff details |
| 2 | Day 3 | "Here's what to expect in week 1" | Process overview and key contacts |
| 3 | Day 7 | "Quick check-in" | "Hi {client_name}, any questions so far?" |
| 4 | Day 14 | "Your first milestone is coming up" | Progress reminder and next steps |

One deal close sets off an entire 2-week automated email flow. No manual follow-up needed.

---

## 8. Heavily Overdue Invoice Escalation Chain

**Complexity:** Advanced (3 rules working together)

| Rule | Trigger | Action |
|------|---------|--------|
| A | `invoice_overdue` (7 days) | `send_email` -- polite reminder |
| B | `invoice_overdue` (30 days) | `send_email` -- firmer tone |
| C | `invoice_overdue` (60 days) | `notify_user` -- "Consider legal action" |

**Rule A email:**
> Subject: "Payment reminder: Invoice #{invoice_number}"
> Body: "Hi {client_name}, just a friendly heads-up that invoice #{invoice_number} is now 7 days past due..."

**Rule B email:**
> Subject: "Outstanding balance: Invoice #{invoice_number}"
> Body: "Dear {client_name}, we'd like to bring to your attention that invoice #{invoice_number} remains unpaid for over 30 days. Please address this at your earliest convenience."

**Rule C notification:**
> Message: "Invoice 60+ days overdue -- consider sending formal notice or legal action"

Three separate rules, same trigger type but different thresholds. Creates a 3-tier escalation: first a friendly nudge, then a serious email, then an internal alert to you.

---

## 9. Full Sales Pipeline Automation

**Complexity:** Complex (6 rules covering entire pipeline)

| Rule | Trigger | Action | Purpose |
|------|---------|--------|---------|
| 1 | `deal_stage_changed` (to: `qualified`) | `send_email` | Welcome qualified lead |
| 2 | `deal_stage_idle` (stage: `qualified`, 7 days) | `create_reminder` | Flag going-cold lead |
| 3 | `deal_stage_changed` (to: `proposal`) | `send_email` | Notify client of proposal |
| 4 | `deal_stage_idle` (stage: `proposal`, 14 days) | `notify_user` | Alert stale proposal |
| 5 | `deal_won` | `start_sequence` | Start onboarding drip |
| 6 | `deal_lost` | `send_email` | Graceful exit email |

**Rule 1 email:**
> Subject: "Thanks for your interest, {client_name}"
> Body: "We're excited to work with you. Here's an overview of our process..."

**Rule 3 email:**
> Subject: "Your proposal is ready, {client_name}"
> Body: "Hi {client_name}, we've put together a proposal for {deal_title}. Please review at your convenience."

**Rule 6 email:**
> Subject: "Sorry to see you go, {client_name}"
> Body: "Hi {client_name}, we understand {deal_title} didn't work out. We'd love any feedback you might have for the future."

Six rules covering the entire journey from qualified lead to close. Every stage transition triggers a client email or internal action. Idle stages get flagged. Won deals start onboarding. Lost deals get a graceful exit. Your entire pipeline runs on autopilot.

---

## 10. The "Zero Churn" System -- Full Client Lifecycle

**Complexity:** Maximum (10 rules + 3 sequences)

### Automation Rules

| Rule | Trigger | Action | Purpose |
|------|---------|--------|---------|
| 1 | `deal_won` | `start_sequence` ("Onboarding") | Welcome new clients |
| 2 | `client_health_low` (threshold: 60) | `send_email` | Early warning check-in |
| 3 | `client_health_low` (threshold: 30) | `start_sequence` ("Re-engagement") | Automated re-engagement |
| 4 | `no_contact_days` (90 days) | `notify_user` | CRITICAL silent client alert |
| 5 | `no_contact_days` (90 days) | `create_reminder` | Schedule retention call |
| 6 | `invoice_overdue` (14 days) | `send_email` | Polite invoice reminder |
| 7 | `invoice_overdue` (45 days) | `notify_user` | Escalate overdue invoice |
| 8 | `deal_won` | `create_invoice_draft` | Auto-create first invoice |
| 9 | `deal_lost` | `start_sequence` ("Win-back") | Attempt to win back |
| 10 | `deal_stage_idle` (stage: `negotiation`, 10 days) | `send_email` | Nudge stalled negotiation |

### Sequence: "Onboarding" (triggered by Rule 1)

| Step | Delay | Subject |
|------|-------|---------|
| 1 | Day 0 | "Welcome aboard, {client_name}!" |
| 2 | Day 3 | "Here's what to expect this week" |
| 3 | Day 7 | "Quick check-in -- how's everything going?" |
| 4 | Day 14 | "Your first milestone is approaching" |

### Sequence: "Re-engagement" (triggered by Rule 3)

| Step | Delay | Subject |
|------|-------|---------|
| 1 | Day 0 | "Hi {client_name}, we miss working with you" |
| 2 | Day 5 | "Here's what's new -- thought you'd find this valuable" |
| 3 | Day 10 | "Can we schedule a quick call, {client_name}?" |

### Sequence: "Win-back" (triggered by Rule 9)

| Step | Delay | Subject |
|------|-------|---------|
| 1 | Day 2 | "We're sorry it didn't work out, {client_name}" |
| 2 | Day 7 | "We'd love another chance -- here's a special offer" |
| 3 | Day 14 | "Last chance: exclusive deal for {client_name}" |

### What This Achieves

- **New clients** get onboarded automatically (4-email drip over 14 days)
- **Quiet clients** get re-engaged at health score 60 (early warning email), then a 3-email drip at score 30
- **90-day silent clients** trigger both an internal alert AND a reminder to call them
- **Invoices** chase themselves at 14 days, alert you at 45 days
- **Won deals** auto-generate an invoice draft AND start onboarding
- **Lost deals** attempt a 3-step win-back campaign
- **Stagnant negotiations** get a direct nudge email after 10 days

The entire system runs on the 5-minute cron job + real-time event hooks. You just manage clients and deals, and the automation handles all outreach, follow-ups, reminders, and alerts.

---

## Cron Setup

The time-based triggers (`deal_stage_idle`, `client_health_low`, `invoice_overdue`, `no_contact_days`) require the cron job:

```
*/5 * * * * flock -n /root/cronlocks/crm-automation.lock /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/process-crm-automation.php --verbose >> /var/log/crm-automation.log 2>&1
```

Event-based triggers (`deal_won`, `deal_lost`, `deal_stage_changed`) fire immediately when the action happens in the UI -- no cron needed.

---

## Where to Find It

- **CRM Dashboard** > top bar "Automation" button
- **Navigation** > CRM Pro dropdown > "Automation" and "Sequences"
- **URLs**: `/crm/automation` and `/crm/sequences`

