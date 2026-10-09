# Star Citizen Referral Randomizer

A WordPress plugin for managing and distributing Star Citizen referral codes fairly.

It provides:

- a **clean admin panel** for managing referral codes
- a **fairness-based AJAX selector** that always favors the least-used codes
- a **REST API endpoint** for adding or updating referral entries from Discord bots or other external systems
- a **frontend shortcode button** that sends users to the Star Citizen signup page with a randomly selected referral code

---

## Features

- **Fair code distribution**
    - Referral codes are selected from the pool with the lowest usage count
    - This helps keep code usage balanced over time

- **Admin management**
    - Add new Discord users and referral codes
    - Reset all usage counters
    - Delete individual users
    - View usage statistics in a table

- **Discord integration**
    - Add or update codes through a REST API endpoint
    - Ideal for use with a Discord bot or webhook

- **Frontend shortcode**
    - Show a button that fetches a referral code via AJAX
    - Redirect users to the RSI enlist page with the selected code

- **WordPress i18n ready**
    - Uses translation functions throughout
    - Includes a `languages` folder for localization files

---

## Installation

1. Upload the plugin folder to:

```plain text
wp-content/plugins/sc-referral-system/
```


2. Make sure the folder contains the plugin file and `languages/` directory.

3. Activate the plugin in **WordPress Admin → Plugins**.

4. If you have translations, place them in:

```plain text
wp-content/plugins/sc-referral-system/languages/
```


---

## Usage

### Admin panel

After activation, a **Star Citizen** menu will appear in the WordPress admin area.

From there you can:

- add referral codes
- reset usage counters
- delete users
- inspect the referral list and usage totals

### Frontend button shortcode

Use this shortcode anywhere on your site:

```plain text
[sc_referrals_button]
```


This renders a button that:

1. requests a referral code through AJAX
2. selects a fair code from the pool
3. opens the RSI enlist URL in a new tab with the referral attached

### Gleam mode

```plain text
[sc_referrals_gleam]
```

Use this shortcode on a page you link to from a Gleam **custom action with API tracking**. The Gleam action is only reported as completed once the visitor's new RSI account has been verified.

Flow:

1. The visitor clicks **Create Star Citizen Account**. A token is created and tied to a referral code picked with the same fairness rules as the normal button. The RSI enlist page opens in a new tab with that code.
2. After signing up, the visitor returns to the page and enters their RSI handle.
3. The plugin loads the public RSI citizen page (`/citizens/<handle>`) and checks that:
    - the account exists
    - it was enlisted on or after the day the token was issued (one day of slack for time zones)
    - the handle hasn't already been used for another token
4. If the checks pass, the page reports the action to Gleam (`gleam.track('<action>')`, plus the `Gleam.push([...])` queue) and fires a `sc-gleam-verified` DOM event.

Setup (WordPress Admin → Star Citizen → **Referrals: Gleam**):

- **Gleam action name**: must exactly match the API-tracking action name in your Gleam campaign
- **Gleam tracking snippet**: paste the script Gleam gives you for API tracking (requires the `unfiltered_html` capability)
- **Token lifetime**: how many days a visitor has to sign up and verify

The same page lists every issued token with its handle and status.

Limitations:

- RSI doesn't publicly show which referral code an account used. The check proves that a *new* account was created after the visitor clicked the link, not that the code was applied.
- `gleam.track()` runs in the visitor's browser, so a determined user could trigger it manually. Use Gleam's own entry review for high-value prizes.
- The enlisted date is read from RSI's HTML. If RSI changes its markup, use the `sc_gleam_enlisted_timestamp` filter to supply the timestamp:

```php
add_filter('sc_gleam_enlisted_timestamp', function ($timestamp, $html, $handle) {
    // return a UTC unix timestamp, or false
    return $timestamp;
}, 10, 3);
```

---

## REST API

The plugin exposes a REST endpoint for adding referral entries externally.

### Endpoint

```plain text
POST /wp-json/sc-referral/v1/add
```


### Request body

Send JSON like this:

```json
{
  "discord_id": "123456789012345678",
  "discord_name": "MyDiscordName",
  "referral_code": "STAR-ABCD-1234",
  "secret": "your-secret-key"
}
```


### Required fields

- `discord_id` — Discord user ID
- `discord_name` — Discord username
- `referral_code` — The Star Citizen referral code
- `secret` — Shared secret key for authentication

### Response examples

#### Success

```json
{
  "message": "Success"
}
```


#### Missing data

```json
{
  "code": "missing_data",
  "message": "Missing ID, name, or code",
  "data": {
    "status": 400
  }
}
```


#### Invalid secret

```json
{
  "code": "no_auth",
  "message": "Invalid secret key",
  "data": {
    "status": 403
  }
}
```


---

## Example: Discord bot integration

A Discord bot can call the REST endpoint whenever a user submits a code.

Example `curl` request:

```shell script
curl -X POST https://example.com/wp-json/sc-referral/v1/add \
  -H "Content-Type: application/json" \
  -d '{
    "discord_id": "123456789012345678",
    "discord_name": "ExampleUser",
    "referral_code": "STAR-ABCD-1234",
    "secret": "your-secret-key"
  }'
```


This will:

- validate the secret
- validate the input
- insert or update the referral record
- initialize the usage count fairly based on the current minimum usage in the system

---

## Fairness logic

When a referral code is requested, the plugin:

1. finds the **lowest usage count** among all stored codes
2. collects all codes with that usage count
3. selects one at random
4. increments that code’s usage counter

This means codes are always balanced as evenly as possible.

---

## Database

The plugin creates a custom table on activation:

- Discord user ID
- Discord username
- referral code
- usage count
- created timestamp

---

## Localization

The plugin is ready for translation using WordPress i18n functions.

### Text domain

```plain text
sc-referral-system
```


### Example translation files

```plain text
languages/sc-referral-system-da_DK.po
languages/sc-referral-system-da_DK.mo
```


---

## Security notes

- The REST endpoint uses a shared secret for authentication
- Keep the secret private and change it before production use
- Admin actions are protected with WordPress nonces
- Input is sanitized before being stored

---

## Shortcode reference

### `[sc_referrals_button]`

Displays the frontend button for fetching a referral code.

### `[sc_referrals_gleam]`

Displays the two-step Gleam flow (referral signup + RSI handle verification). See [Gleam mode](#gleam-mode).

---

## Notes

- The plugin currently uses a simple shared-secret approach for Discord integration
- If you want stronger security later, you could add:
    - application passwords
    - signed requests
    - IP restrictions
    - bot-specific authentication

---

## License

GPL3

---

If you want, I can also write:

1. a **more polished GitHub-style README**
2. a **developer README** with endpoint examples and plugin hooks
3. a **changelog section** and installation screenshots layout