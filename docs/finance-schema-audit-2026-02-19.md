# Finance Schema Audit (2026-02-19)

## Scope
- Source of truth: live DB schema from active Laravel connection (`mysql`) on 2026-02-19.
- Tables audited: `noci_fin_branches`, `noci_fin_coa`, `noci_fin_tx`, `noci_fin_tx_lines`, `noci_fin_approvals`, `noci_fin_settings`, `noci_fin_payroll`, `noci_fin_payroll_items`, `noci_fin_attachments`.

## Baseline (schema baku used by code)
- `noci_fin_tx.status`: enum lowercase (`draft`, `pending`, `approved`, `posted`, `rejected`).
- `noci_fin_tx`: no `party_name`, no `bukti`, no `notes` columns.
- `noci_fin_tx_lines`: uses `line_desc` (not `description`), and supports `party_name`.
- `noci_fin_approvals`: uses `status`, `note`, `requested_by`, `requested_role`, `requested_at`, `approved_by`, `approved_at`.
- `noci_fin_branches.mode`: enum (`standalone`, `consolidated`).
- `noci_fin_settings`: wide-row config per tenant (not key/value shape).
- `noci_fin_payroll_items`: uses `run_id`, `deduction`, `fee_install`, `fee_sales`, `fee`, `total`.

## Gap mapping (before fix)
- Controller used uppercase statuses (`PENDING/POSTED/REJECTED`) while DB enum is lowercase.
- Controller attempted writing non-existent transaction columns (`party_name`, `bukti`, `notes`) on `noci_fin_tx`.
- Transaction lines were written to `description` field while table uses `line_desc`.
- Approval writes used `action/notes/user_id` while table uses `status/note/requested_*/approved_*`.
- Finance action logs used wrong `ActionLog::record()` parameter order.
- Backend finance routes had no server-side permission guard per action.

## Implementation decision
- Finance module code now follows the live DB schema above as canonical baseline.
- API response keeps uppercase status labels for UI compatibility, but DB read/write uses lowercase status values.
