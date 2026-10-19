# TypeSafe in the WhatsApp plugin

**TypeSafe decides what to do** for every shopper message, in one call: which action, which language, which FAQ, pickup or delivery. Nothing else ever chooses the action.

- **Code runs** the tool when the decision is complete (basket, checkout, tracking, hours, language, pickup/delivery "as soon as possible"…). Marvin only words the result: short writer prompt, no tools.
- **Marvin fills in the details** when the decision needs something only he can read from the message: which items, how many, an exact time. He gets the full menu but **only the tools the branch allows** (`products` → `filter_products`, `add_to_order`, `change_logistics`; `remove` → `get_cart`, `remove_from_order`). Any other tool call is blocked and logged.
- **Sure enough** means TypeSafe's confidence is at least `settings.threshold` (0.5), or the top branch is at least threshold − 0.1 and leads the second by `settings.min_lead` (0.15), as with a "yes" only one reading fits.
- **Fallback:** when TypeSafe is down or not sure, the branch is `unclear`, or there is nothing to run, the shopper gets the fixed translated fallback text, "Sorry, I can't help you with this at the moment." (no AI call), and the reason is logged.

## Flow of one message

```
WhatsappController::receive()
  handleCustomer()        LanguageDetector: language of a new shopper, saved only from a real sentence
  reply()                 new shopper → welcome message, no TypeSafe call
    marvinReply()
      decideWithTypeSafe()  DecisionService::decide(): one TypeSafe call → Decision
      DecisionHandler::reply(Decision)
        tool:<name>   → code runs it, Marvin words the result (writer, no tools)
        marvin        → Marvin::replyWithTools(branch tools)   products (show and order), remove
        tool:change_logistics → code for "asap", else Marvin with change_logistics
        faq           → approved text (no Claude call), or Marvin answering only from facts / stores / a tool
        handoff       → Marvin with the handoff text: apologises, can't arrange it in this chat
        language      → detect_language
        reply         → one kind line ("thanks", "ok"), no tools
      unsure / down / unclear / nothing to run → fixed fallback reply + log "typesafe: fallback"
```

The webhook response has a `typesafe` field with the decision, `allowed_tools` (what Marvin may use for the branch), `called_tools` (what actually ran), `outcome` (`acted`, `marvin_tools`, `fallback` or `no_text`) and `fallback_reason`.

## Classes (`src/TypeSafe/`)

| Class | Does |
|---|---|
| `TypeSafeClient` | One request to `POST /v1/systemone`. Retries 429/5xx/529 with short backoff, gzips large bodies, throws `TypeSafeException` |
| `TypeSafeResult`, `TypeSafeAnswer` | Typed answers: `choice()`, `noul()`, `score()` |
| `StateBuilder` | What TypeSafe sees: latest message, last turns, basket (with line ids), order on its way, checkout link sent. **Read-only**: never creates or updates a cart |
| `MenuDigest` | The shop's menu trimmed to names + short descriptions. Not used by the decision any more (Marvin reads items with the full menu) |
| `FlowLoader`, `Flow` | The shop's flow: the shared `flows/default.flow.json` merged with the shop's own `flow.json` |
| `DecisionService` | Flow → questions, one call, a `Decision`. Never throws: errors give a fallback decision |
| `Decision` | The result, the branch's allowed tools, plus `cannotAct()` / `reason()` |
| `DecisionHandler` | Runs the decision: code, Marvin with the branch's tools, or the fallback; returns the reply in Marvin's reply shape |
| `OrderSlots` | Decision → `change_logistics` input for pickup/delivery "as soon as possible" |
| `LanguageDetector` | First-message language; saved only with ≥ `save_min_words` words and ≥ `save_min_confidence` |

Marvin gets one of two inputs:

- **`Marvin::replyWith($calls, $note)`, when code ran the tool (writer mode).** The calls run in code first, through the same `MarvinTools::run()` Marvin uses, so cart, tracking, buttons and links behave as before. Their results and the note go on the shopper's message as one internal note. Marvin gets the **writer prompt** (`marvin_writer.v1.txt`), the last 8 turns and **no tools**.
- **`Marvin::replyWithTools($tools, $note)`, when he fills in the details.** Full prompt and menu, but only `$tools`. The note says what TypeSafe decided and to ask (not guess) when something needed is not said. He may answer without a tool call when he has to ask ("which pizza?"); that is logged as `marvin: no tool called`.

`Marvin::reply()` (Marvin with all tools, deciding himself) is not used by the WhatsApp flow.

## Building an item with options

Products with option groups are built over several messages by `add_to_order`, which keeps a **draft** in Redis per shop and phone (`ItemDraft`; expires after 15 minutes without an answer, `marvin.draft_ttl` in seconds):

| Product | What happens |
|---|---|
| no groups | added at once |
| only required groups (pizza: size, crust) | `needs_options`: all required groups asked in one message → added when chosen |
| required + optional (Build Your Own Bowl) | required first, all at once → then each optional group once (`ask_optional`: "How about toppings? You can add: …") → added when the last one is answered or declined |

- Each call only carries the new option ids; earlier choices are kept. A new pick in a single-choice group replaces the old one, multiple-choice picks add up.
- `skip_optional`: no thanks to the group just offered. `no_more_options`: add it now. `discard`: forget the item.
- **Changing an item already in the basket** ("add corn to the bowl", "make it quinoa", "no cucumber"): `change_existing` with only the change (`option_ids` to add or swap in, `remove_option_ids` to take out). Code reads the line's current options, applies the change and replaces the line with the same quantity in one cart update; the latest line of that product, or `line_id`. A change that would leave a required choice empty changes nothing. Never a second item. `products` has `get_cart` so Marvin can tell several of the same product apart.
- TypeSafe sees the draft as `item_in_progress`, so "salmon" or "no thanks" route to `products`; Marvin gets it in his note, so an option with the same name as a menu item (Slow Cooked Beef) is read as the option.
- **Checkout with an unfinished item:** nothing is checked out yet; Marvin says what is still open and asks whether to finish it or check out without it. Asked once: checking out again leaves it out (the draft is dropped). Any new choice resets that.
- **Cleared** when the item is added or discarded, when `checkout_order` succeeds in the chat, and when a web checkout completes (Cart plugin), so nothing half-built carries into the next order.
- Redis down: nothing is remembered between messages, so the optional groups are skipped and `needs_options` says `remembered: false`, telling Marvin to pass every choice so far.

## Configuration (secret JSON)

```json
"typesafe": {
  "api_key": "…",                 // or the TYPESAFE_API_KEY environment variable
  "model": "jev-latest",
  "timeout": 10,
  "max_retries": 2,
  "history_turns": 4,
  "flow_path": "/home/demo.data/{{shop_id}}/flow.json"
}
```

Without a key the service still starts, but every message gets the fallback reply (`outcome: fallback`).

In the `marvin` block, two prompts, the same for every shop:

```json
"prompts": {
  "writer": "/home/demo.data/marvin_prompts/marvin_writer.v1.txt",
  "menu":   "/home/demo.data/marvin_prompts/marvin_menu.v1.txt"
}
```

| Prompt | Used when | Tools |
|---|---|---|
| `marvin_writer.v1.txt` | code ran the tool, FAQ, handoff, language, "thanks", photos | none |
| `marvin_menu.v1.txt` | Marvin fills in the details (products, remove, exact time) | only the branch's tools |

Both are in the repo root. Shop data comes from placeholders: `{{COMPANY_NAME}}`, `{{CLIENT_NAME}}`, `{{SUPPORT_1}}`, `{{SUPPORT_2}}` (writer); `{{COMPANY_NAME}}`, `{{MENU_JSON}}`, `{{PICKUP_HOURS}}`, `{{DELIVERY_HOURS}}`, `{{LAST_ORDER_TODAY}}` (menu; the customer's name goes in the note so the prompt cache is shared by all shoppers). There is no other prompt to fall back to: a missing or unreadable prompt gives the fallback reply (`fallback_reason: marvin_failed`). `marvin.prompts.main`, `shops`, `wine_specialist` and `writer_shops` are no longer used; `reminder` is still used by the reminder script.

Optional: `"writer_model": "<claude model id>"` to word writer replies with another (e.g. cheaper) model than `marvin.model`.

## Flows

- **`flows/default.flow.json`** (repo): used by **every** shop. Router branches, generic FAQs that answer from shop data (opening hours, locations, and `about_shop` from the shop's background page; that one is only offered to TypeSafe when the shop has a page in `web_sources`), the handoff, languages, thresholds.
- **`/home/demo.data/{shop_id}/flow.json`** (server, optional): only what is specific to that shop. Merged by `id`: an existing entry is updated field by field, a new one is added, `"disabled": true` removes a shared one. Texts merge per key and language.

Shop data is never copied into a flow: menu, branches, hours, shop name and support contacts come from the code at runtime (`answer_from: "stores"`, `do: "tool:get_ordering_hours"`, `{{SHOP_NAME}}`, `{{SUPPORT_1}}`, `{{SUPPORT_2}}`).

### Router branch

```json
{ "id": "products", "do": "marvin", "tools": ["filter_products", "add_to_order", "change_logistics"],
  "description": "Is about menu items: wants to see, know about or order products …" }
```

| `do` | What happens |
|---|---|
| `tool:<name>` | code runs the tool, Marvin words the result. With `tools`, Marvin gets those when code cannot fill the input (e.g. a pickup time other than "asap") |
| `marvin` | Marvin with only the listed `tools` (required) |
| `faq`, `language`, `handoff` | see below |
| `reply` | nothing to do: one kind line, no tools |
| `fallback` | the fixed fallback reply |

`description` is what TypeSafe chooses on: keep descriptions distinct, overlapping ones cause confident wrong answers. Optional `instructions` are passed to Marvin.

### FAQ entry

Exactly one way of answering:

| Key | Reply |
|---|---|
| `"answer": "<text key>"` | the approved text, sent as is when it exists in the shopper's language (no Claude call); otherwise Marvin passes it on translated |
| `"facts": "<text key>"` | Marvin answers only from these facts |
| `"answer_from": "stores"` | Marvin answers only from the shop's branches (address, hours, contact) |
| `"do": "tool:<name>"` | the tool runs, Marvin replies from its result |

Optional: `"instructions"` (how Marvin should answer), `"ask": {"question": "<text key>"}` (a follow-up to ask first).

### Checking a flow file

A broken shop file never takes a shop down: the errors are logged and the shop gets the default flow. To check a file before uploading it:

```php
$errors = $container->get(FlowLoader::class)->check('/path/to/flow.json');   // [] = valid
```

## Deploying

1. Add the `typesafe` block (with the key) and `marvin.prompts.writer` / `marvin.prompts.menu` to the server's secret config, and copy `marvin_writer.v1.txt` and `marvin_menu.v1.txt` to `/home/demo.data/marvin_prompts/`.
2. Copy shop flows, if a shop has one, to `/home/demo.data/<shop id>/flow.json` on the server. No shop has one yet: `test-env/flows/99.flow.json.draft` is set aside (its content came from the real Poké Perfect prompt and does not match the demo).
3. Check the log for `typesafe: decision` lines (`local_log.level: info`).

A data processing agreement with TypeSafe should be in place before real customer messages go through it.

## Local development

`test-env/` runs MariaDB, Redis and the app (shop 99):

```
cd test-env
docker compose up -d                 # webhook: POST http://localhost:8080/v2/whatsapp
docker compose restart app           # after code or config changes: Docker Desktop may keep serving old copies otherwise
docker compose down -v && docker compose up -d   # fresh database from seed.sql
```

`test-env/flows/<shop id>.flow.json` is copied to `/home/demo.data/<shop id>/flow.json` at startup.

To test with the same WhatsApp number again, reset it (refuses to run when `env` is `prod`):

```
docker compose exec app php /opt/pmsrapi/v2/plugins/Whatsapp/bin/reset_shopper.php <phone>                   # like a new shopper (past orders stay)
docker compose exec app php /opt/pmsrapi/v2/plugins/Whatsapp/bin/reset_shopper.php <phone> --language-only   # only forget the saved language
docker compose exec app php /opt/pmsrapi/v2/plugins/Whatsapp/bin/reset_shopper.php <phone> --orders-too      # also remove past orders
```

## Logs

| Line | Means |
|---|---|
| `typesafe: decision` | what TypeSafe decided (branch, confidence, tokens, seconds) |
| `typesafe: running tool` / `answering FAQ` / `handoff` / `switching language` | code acted |
| `typesafe: Marvin with tools` | Marvin fills in the details; `tools` says which he may use |
| `marvin: tool call` / `marvin: tool blocked` / `marvin: no tool called` | what Marvin did with them |
| `typesafe: fallback` | fallback reply: `why` (`typesafe_*` error, `low_confidence`, `unclear`, `not_handled`), the message, and TypeSafe's top 2 branches |
| `typesafe: decision failed` | TypeSafe error; `code` says which |
| `marvin: writer reply` / `writer tool call` | writer mode: which calls ran, prompt size, model |
| `typesafe: language` | first-message language, and whether it was saved |

## Not done yet

- **Products built over several messages** (Build Your Own Bowl): Marvin builds them with `add_to_order`, nothing in code keeps the half-built item yet.
- **Shop-specific FAQs for shop 99.** `test-env/flows/99.flow.json.draft` holds a draft taken from the real Poké Perfect prompt; it is not used until it has the demo's own answers.
