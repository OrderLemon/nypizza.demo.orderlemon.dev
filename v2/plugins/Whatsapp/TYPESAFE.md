# TypeSafe in the WhatsApp plugin

TypeSafe makes the **decisions** for each shopper message (what to do, which language, which FAQ, which menu item, size and quantity). Code **acts** on them through Marvin's existing tools. Marvin only **writes** the reply: he never decides or runs tools on his own. When code cannot act on a decision, Marvin (without tools or menu) asks one clarifying question, replies kindly, or says he cannot help with this here and gives the team contacts. When TypeSafe fails, a fixed translated "can't help right now" is sent.

## Flow of one message

```
WhatsappController::receive()
  handleCustomer()        LanguageDetector: language of a new shopper, saved only from a real sentence
  reply()                 new shopper → welcome message, no TypeSafe call
    marvinReply()
      decideWithTypeSafe()  DecisionService::decide(): one TypeSafe call → Decision
      DecisionHandler::reply(Decision)    acts when confident, else null
        tool:<name>   → Marvin::replyWith(tool)       the tool runs first, Marvin replies from its result
        show_products → one item: filter_products · "the menu": its categories · "something with chicken": matching items (2nd small call)
        order         → add_to_order (OrderSlots)      + change_logistics when "pickup/delivery asap"
        faq           → approved text (no Claude call), or Marvin answering only from facts / stores / a tool
        handoff       → Marvin with the handoff text and support contacts
        language      → detect_language
      nothing to act on → safe reply      Marvin, no tools/menu: asks, replies kindly, or says he can't help + team contacts
      TypeSafe failed   → fixed reply     marvin_fallback text, translated, no AI
      no text (photo)   → "I read text and voice messages"
```

The webhook response has a `typesafe` field with the decision and `outcome`: `acted`, `safe`, `unavailable` or `no_text`.

## Classes (`src/TypeSafe/`)

| Class | Does |
|---|---|
| `TypeSafeClient` | One request to `POST /v1/systemone`. Retries 429/5xx/529 with short backoff, gzips large bodies, throws `TypeSafeException` |
| `TypeSafeResult`, `TypeSafeAnswer` | Typed answers: `choice()`, `noul()`, `score()` |
| `StateBuilder` | What TypeSafe sees: latest message, last turns, basket (with line ids), order on its way, checkout link sent. **Read-only**: never creates or updates a cart |
| `MenuDigest` | The shop's menu trimmed to names + short descriptions, options keyed `p{product id}`; config groups (Size, Base, …) once |
| `FlowLoader`, `Flow` | The shop's flow: the shared `flows/default.flow.json` merged with the shop's own `flow.json` |
| `DecisionService` | Flow + menu → questions, one call, a `Decision`. Never throws: errors give a fallback decision |
| `Decision` | The result, plus `useMarvin()` / `reason()` |
| `DecisionHandler` | Acts on a confident decision; returns the reply in Marvin's reply shape |
| `OrderSlots` | Decision → `add_to_order`, `change_logistics`, `filter_products`, `remove_from_order` input |
| `LanguageDetector` | First-message language; saved only with ≥ `save_min_words` words and ≥ `save_min_confidence` |

Marvin gets one of two inputs:

- **`Marvin::replyWith($calls, $note)`, when TypeSafe decided (writer mode).** The calls run in code first, through the same `MarvinTools::run()` Marvin uses, so cart, tracking, buttons and links behave as before. Their results, the products they name and the note (branch, confidence, instructions, facts) go on the shopper's message as one internal note. Marvin gets the **writer prompt** (`marvin_writer.v1.txt`: his voice plus how to word a result), the last 8 turns and **no tools**: one plain call, he only words the reply. About 3,300 characters instead of about 88,700 for shop 99. Without a writer prompt file the full prompt is used, with the calls as his own tool calls.
- **`Marvin::replyWithNote($note)`, when TypeSafe could not decide on its own** (no clear menu item, no clear basket line): the full prompt and all tools, with what TypeSafe read as a note. Marvin decides himself.

`Marvin::reply()` (TypeSafe unsure, `unclear`, or an error) is unchanged: full prompt, all tools.

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

Without a key the service still starts, but every message gets the fixed "can't help right now" reply (`outcome: unavailable`). `Marvin::reply()` (Marvin deciding on his own with the full prompt) is no longer used by the WhatsApp flow.

In the `marvin` block:

```json
"prompts": {
  "writer": "/home/demo.data/marvin_prompts/marvin_writer.v1.txt",
  "writer_shops": { "99": "/path/to/a/shop/specific/writer.txt" }
}
```

`marvin_writer.v1.txt` is in the repo root next to the other prompts. Placeholders: `{{COMPANY_NAME}}` (shop name), `{{CLIENT_NAME}}`, `{{SUPPORT_1}}`, `{{SUPPORT_2}}`. `writer_shops` is optional, for a shop with its own voice.

Optional, in the `marvin` block: `"writer_model": "<claude model id>"` to word TypeSafe-decided replies with another (e.g. cheaper) model than the one Marvin decides with. Without it, the configured `marvin.model` is used.

## Flows

- **`flows/default.flow.json`** (repo): used by **every** shop. Router branches, generic FAQs that answer from shop data (opening hours, locations), the handoff, languages, thresholds.
- **`/home/demo.data/{shop_id}/flow.json`** (server, optional): only what is specific to that shop. Merged by `id`: an existing entry is updated field by field, a new one is added, `"disabled": true` removes a shared one. Texts merge per key and language.

Shop data is never copied into a flow: menu, branches, hours, shop name and support contacts come from the code at runtime (`answer_from: "stores"`, `do: "tool:get_ordering_hours"`, `{{SHOP_NAME}}`, `{{SUPPORT_1}}`, `{{SUPPORT_2}}`).

### Router branch

```json
{ "id": "handoff", "do": "handoff",
  "description": "Wants money back, a discount or a price correction",
  "instructions": "Apologise once. Give the team contacts from the facts." }
```

`do`: `tool:<marvin tool>`, `order`, `faq`, `language`, `handoff`, `marvin`. `description` is what TypeSafe chooses on: keep descriptions distinct, overlapping ones cause confident wrong answers.

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

1. Add the `typesafe` block (with the key) to the server's secret config.
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
| `typesafe: decision` | what TypeSafe decided (branch, confidence, item, options, tokens, seconds) |
| `typesafe: running tool` / `adding to order` / `answering FAQ` / `handoff` / `switching language` | the handler acted |
| `typesafe: not handled, using Marvin` | confident, but nothing to act on for that branch |
| `typesafe: menu overview` / `showing matching products` / `matching products` | "show me the menu" / "something with chicken" handled |
| `typesafe: decision failed, using Marvin` | TypeSafe error; `code` says which |
| `marvin: seeded tool call` | Marvin replying from a tool TypeSafe ran |
| `marvin: writer reply` / `writer tool call` | writer mode: which calls ran, prompt size, model |
| `typesafe: language` | first-message language, and whether it was saved |

## Not done yet

- **Several options in one group** (e.g. 3 toppings). TypeSafe picks one; Marvin asks for the rest.
- **A specific pickup or delivery time** ("om 19:00", at 19:00). Only "as soon as possible" is set directly; Marvin handles the rest.
- **Shop-specific FAQs for shop 99.** `test-env/flows/99.flow.json.draft` holds a draft taken from the real Poké Perfect prompt; it is not used until it has the demo's own answers.
