# Testing

This plugin has a single rule: **the activation funnel must stay test-covered.**

The settings-page Connect flow is the primary activation surface (see [User-research note](#user-research-note)). Regressions there mean admins can't connect, which means the plugin is functionally dead — even if every other feature works. Treat tests around it the same way you'd treat tests around a payment endpoint.

## Critical paths that MUST stay covered

| Path | Where it lives | Test |
|---|---|---|
| Settings page Connect button renders + reacts | `settings.php`, `amd/src/settings.js`, `amd/src/connect.js` | `tests/behat/settings_connect.feature` |
| API key field hidden by default; revealed on "Paste manually" | `settings.php`, `amd/src/settings.js` | `tests/behat/settings_connect.feature` |
| Connected state shows redacted key + Disconnect | `settings.php`, `amd/src/settings.js`, `classes/external/disconnect.php` | `tests/behat/settings_connect.feature`, `tests/external/disconnect_test.php` |
| Sticky block instance created on install | `db/install.php` | `tests/install_test.php::test_install_creates_sticky_block_instance` |
| Bell-icon notification sent to all admins on install | `db/install.php`, `classes/local/setup_helper.php` | `tests/install_test.php::test_install_sends_admin_notification`, `tests/setup_helper_test.php` |
| Setup banner respects `setup_complete` flag | `lib.php`, `classes/external/complete_setup.php` | `tests/external/complete_setup_test.php` |
| Course nav node added for users with view capability | `lib.php` | `tests/navigation_test.php` |
| Connect nonce uniqueness + format | `classes/external/generate_connect_nonce.php` | `tests/external/generate_connect_nonce_test.php` |
| Dashboard URL constant + `forced_plugin_settings` override | `classes/api_client.php` | `tests/api_client_test.php` |
| Install must succeed even if dashboard ping fails | `db/install.php` | `tests/install_test.php::test_install_silent_on_ping_failure` |

## The rule

**Any change to** any of these files **requires running PHPUnit AND Behat locally before commit:**

- `settings.php`
- `db/install.php`
- `db/upgrade.php`
- `db/hooks.php`
- `db/messages.php`
- `db/services.php`
- `classes/api_client.php`
- `classes/local/setup_helper.php`
- `classes/hook_callbacks.php`
- `classes/external/generate_connect_nonce.php`
- `classes/external/disconnect.php`
- `classes/external/complete_setup.php`
- `lib.php`
- `callback.php`
- `amd/src/connect.js`
- `amd/src/settings.js`
- `amd/src/setup_banner.js`

CI runs the full suite on every push (matrix across Moodle 4.4 / 4.5 / 5.0 / 5.1 / 5.2), but a green CI is a slow signal. Local runs catch regressions before the PR.

## Running tests locally

### PHPUnit

One-time setup (per Moodle install). Add to your Moodle's `config.php` before `require_once(__DIR__ . '/lib/setup.php');`:

```php
$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/path/to/moodledata_phpu';
```

Then install Moodle's dev dependencies and initialise the test environment:

```bash
cd /path/to/moodle
php composer.phar install
php admin/tool/phpunit/cli/init.php
```

Run the plugin's PHPUnit suite:

```bash
cd /path/to/moodle
vendor/bin/phpunit --testsuite block_mastermind_assistant_testsuite
```

Run a single file:

```bash
vendor/bin/phpunit blocks/mastermind_assistant/tests/api_client_test.php
```

Run a single test method:

```bash
vendor/bin/phpunit --filter test_dashboard_url_uses_constant_by_default \
  blocks/mastermind_assistant/tests/api_client_test.php
```

### Behat

One-time setup:

```bash
cd /path/to/moodle
php admin/tool/behat/cli/init.php
```

Run the plugin's Behat features:

```bash
cd /path/to/moodle
vendor/bin/behat \
  --config=$(pwd)/behatdata/behatrun/behat/behat.yml \
  blocks/mastermind_assistant/tests/behat/
```

Run a single feature:

```bash
vendor/bin/behat \
  --config=$(pwd)/behatdata/behatrun/behat/behat.yml \
  blocks/mastermind_assistant/tests/behat/settings_connect.feature
```

If Behat is too heavy to set up locally, push to a feature branch and let CI exercise it. The matrix runs Behat on every supported Moodle version — local runs are an optimisation, not a gate.

### Quick lint loop

For JS-only changes:

```bash
cd /path/to/moodle
./node_modules/.bin/eslint blocks/mastermind_assistant/amd/src/ \
  --no-eslintrc --config .eslintrc
```

For PHP-only changes:

```bash
PATH=/path/to/php/bin:$PATH \
  php -l blocks/mastermind_assistant/<file>.php
```

For full pre-commit confidence run [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) locally — it mirrors the CI exactly.

## User-research note

The settings page is the primary activation surface because **real admins go straight to *Site administration → Plugins → Blocks → Mastermind Assistant → Settings* before they ever notice the in-page block**.

Two independent users at Escola Bahiana de Medicina e Saúde Pública (Thiago + a faculty member) confirmed this on 2026-05-06. Thiago activated the plugin manually by:

1. Going to mastermindassistant.ai
2. Creating an account
3. Copying his API key
4. Pasting it into the Moodle settings page

Six manual steps replacing what was meant to be one Connect click. The block lives in `side-pre`, which the default Boost theme renders on the right side and most users overlook entirely.

**Do not move activation UX out of the settings page** without re-validating with users. The block-side Connect button stays as a contextual companion, but the settings page is the funnel. The course-context navigation tab (added in Plan B) is a discoverability fallback for environments where the side-column block isn't rendered.

Implementation rationale and follow-up plans are preserved in `docs/superpowers/plans/`.

## When you find a gap

If you find a bug that *should* have been caught by an existing test, write a failing test first that reproduces it, then fix the bug. The new test stays.

If you find a critical path missing from the table above, add a row + a test in the same PR that introduces or modifies it. Don't ship coverage debt.
