---
name: list-issues
description: List, filter and look up Sidra Desk issues (bugs and feature requests, stored in the `reports` table) straight from the app's database. Use this whenever the user wants to see issues or asks about them in any form, such as "list issues", "what's open", "show urgent bugs", "anything overdue?", "issues for Acme Website", "what did Ben report this week", "show me AW-6", "how many issues are in progress", even if they don't say "list". Do not use it for GitHub issues, or for changing issues (it is read-only).
---

# List Sidra Desk issues

Sidra Desk issues live in the `reports` table (model `App\Models\Report`). Each has a key like `AW-6` (project prefix + number), a type (`bug` or `feature`), a status (`open`, `in_progress`, `resolved`, `closed`, `reopened`), a priority (`low` to `urgent`), a severity for bugs, a project (which belongs to a company), a reporter, optional due date, page URLs and labels.

The bundled script queries this directly and prints a Markdown table. It is much faster and more reliable than writing ad-hoc tinker queries, so use it rather than reinventing the query.

## How to run it

From the app's root folder (`/Applications/ServBay/www/client-1`):

```bash
php .claude/skills/list-issues/scripts/list_issues.php [options]
```

It reads whatever database the app's `.env` points at and prints that database's name on the first line. Locally that is the dev database `client`. It never writes anything.

## Turning the request into options

Comma-separated values mean "any of these". Text filters are case-insensitive "contains" matches.

| The user says | Options |
|---|---|
| "list issues", "what's open" | *(none)*: active issues (open, in progress, reopened) |
| "all issues", "including closed" | `--status=all` |
| "resolved / closed / done" | `--status=resolved`, `--status=closed`, `--status=finished` |
| "urgent", "high priority" | `--priority=urgent` or `--priority=high,urgent` |
| "bugs" / "feature requests" | `--type=bug` / `--type=feature` |
| "critical bugs" | `--type=bug --severity=critical` |
| "for Acme Website", "AW issues" | `--project=website` or `--project=aw` (name or prefix) |
| "for Acme" (a client company) | `--company=acme` |
| "reported by Ben" | `--reporter=ben` (name or email) |
| "labelled frontend" | `--label=frontend` |
| "about checkout", "on the /cart page" | `--search=checkout` (title, description and page URLs) |
| "overdue", "late" | `--overdue` |
| "this week", "since 1 Oct" | `--since=7d`, `--since=2026-10-01` (also `2w`, `today`) |
| "show AW-6", "details of BOS-2" | `--key=AW-6`: the full issue with description, page URLs, labels, resolution and recent activity |
| "most urgent first" / "by due date" | `--sort=priority` / `--sort=due` (default is newest first; also `updated`) |
| "with the URLs / labels" | `--details` adds Page URLs and Labels columns |
| counting or further processing | `--json` |

The default limit is 50 rows. The footer says "Showing X of Y". If Y is larger and the user wants everything, rerun with a higher `--limit`.

Each row shows its status, so a question that mixes states, like "how many has Chloe reported, and which aren't done?", needs one `--status=all` run read by the Status column, not several runs.

A status the user names but the script doesn't recognise is reported with the valid choices. Map synonyms yourself before calling it: "in progress" → `in_progress`, "done" → `finished`.

## Presenting the result

- Show the table the script prints, trimmed of columns that every row shares if that helps readability. Keep the issue key, since the user refers to issues by it.
- Then add a short summary with the total, how many are urgent or critical, and which are overdue (marked `⚠ overdue`). Only add this when it's useful, not as boilerplate.
- When the database line shows something other than the local dev database, say which one it was, so the user knows whose data they are looking at.
- For "how many" questions, answer with the number first, then the table if it's short.

## Production data

The script only sees the database in the local `.env`, which is the dev copy, not production. If the user asks about live or production issues, say that this shows local data. Offer to run the same script on the server, for example over SSH from the app folder there. Don't point the local `.env` at production yourself.
