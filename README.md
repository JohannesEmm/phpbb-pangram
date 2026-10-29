# Pangram AI Post Checker — phpBB Extension

A phpBB 3.3.x extension that automatically checks first posts from new users for AI-generated content using the [Pangram](https://pangram.com) API.

- Posts that score below a configurable threshold are **auto-approved** and the user is activated
- Posts that score above a configurable threshold trigger an **automatic permanent ban** (by user ID and email) and the post is deleted
- Posts in between are left in the moderation queue for manual review

All bans are logged in the phpBB Moderator Log with the Pangram score.

## Requirements

- phpBB 3.3.x
- PHP 7.2+
- A [Pangram](https://pangram.com) account and API key

## Installation

1. Download and unzip into `ext/claude/pangram/` in your phpBB root
2. In ACP → Customise → Manage extensions, enable **Pangram AI Post Checker**
3. Go to ACP → Customise → Pangram AI Checker → Settings and enter your API key

## Configuration

All settings are in ACP → Customise → Pangram AI Checker:

| Setting | Default | Description |
|---|---|---|
| Enable checker | Yes | Turn the extension on/off without disabling it |
| API key | _(empty)_ | Your Pangram API key (`sk-pg-…`) |
| Auto-approve threshold | 10 | Auto-approve + activate user if AI score ≤ this % |
| Auto-ban threshold | 90 | Permanently ban user + delete posts if AI score ≥ this % |

## How it works

The extension hooks `core.submit_post_end`. When a new post arrives:

1. Checks if the poster has zero approved posts (first post only)
2. Strips BBcode/HTML and sends the text to Pangram's API (`pangram-4` model)
3. Polls until Pangram returns a result (up to 15 seconds)
4. Acts on the `fraction_ai` score:
   - ≤ approve threshold → approve post, increment post count, activate user
   - ≥ ban threshold → ban by user ID + email, soft-delete all posts, log to MCP
   - Between thresholds → leave in queue for manual review

Posts shorter than 50 characters are skipped. If the Pangram API is unavailable or returns an error, the post is left in the queue.

## Cost estimate

At roughly 300 words per post and 2 first posts per day, expect around **$0.20–$0.30/month** in Pangram API costs (based on Pangram's pricing as of late 2024).

## License

GPL-2.0
