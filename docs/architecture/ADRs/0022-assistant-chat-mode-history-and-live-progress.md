# ADR-0022 - Assistant as a full-screen chat with saved history and live progress (amends ADR-0017)

- **Status:** Accepted (project owner decision 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). Amends ADR-0017 for the rows in the Approval record below only.
- **Date:** 2026-11-13
- **Related:** ADR-0017 (stateless assistant, "nothing is stored"), ADR-0018, ADR-0019, `docs/architecture/threat-model.md`, `docs/deployment/assistant.md`.

## Context

The side panel was judged too small and too limited. The owner asked for the assistant to behave like ChatGPT or Claude: a toggle next to the language button that hides the sidebar and everything else and turns the screen into a chat, with example questions that change, saved chats, and visible progress ("thinking", "searching") while an answer is prepared. ADR-0017 forbids storing conversations and returns one response per turn, so two of these points change the contract and need explicit approval.

## Decision

1. **Chat mode (UI only).** The header toggle switches the whole application shell to a full-screen chat. Past chats are listed on the side; example questions are drawn from a pool and change on their own while the chat is empty. No contract change.
2. **Saved history, per user, on the server.**
   - Tables `assistant_conversations` and `assistant_messages` (migration `2026_11_26_000100`). A conversation belongs to one user and one branch (the branch active when it started).
   - Title and every message (text and citations) are **encrypted at rest** (`Crypt`, `APP_KEY`), like the provider key in ADR-0019.
   - Only the owner can list, open or delete a conversation: `GET /assistant/conversations`, `GET /assistant/conversations/{id}`, `DELETE /assistant/conversations/{id}`, `DELETE /assistant/conversations` (all of the user's chats in the active branch). All need `assistant.use`.
   - The list shows the active branch's chats only, so switching branch never shows another branch's data.
   - `POST /assistant/chat` stays stateless in what it trusts (the client still sends the conversation, the model sees only that) and additionally saves the turn; it returns `conversation_id`, and accepts one to continue a chat. A foreign or unknown id starts a new chat; it never writes into another user's chat.
   - **Retention:** setting `assistant.history_retention_days` (default **90**, `0` = chats are not saved, maximum 3650). `assistant:prune-history` runs daily and deletes older chats (everything when the value is `0`). Deleting a user deletes their chats.
   - The audit row `assistant.chat` is unchanged and still never contains question or answer text.
3. **Live progress.** New `POST /assistant/chat/stream` runs the same turn and answers with newline-delimited JSON (`application/x-ndjson`): `thinking`, `tool` (catalogue tool name and entity only, never arguments or data), then `answer` (the same payload as `/chat` plus `conversation_id`) or `error`. Refusals that happen before the turn (disabled, not accepted, no key, budget, validation, branch) are ordinary JSON errors with their usual status, so the stream starts only for a turn that will run. `/assistant/chat` remains for clients that cannot stream. The answer arrives as one event: text is not streamed token by token.
4. **Answer display.** The model's light markdown (bold, lists, headings) is parsed into text nodes; no HTML is produced. Record numbers and names the answer prints become links, and the link target comes only from server-built citation `labels` (`number`, `name`, `name_ar`, `name_en` of the cited rows), never from the model's text. Citations carry the new optional `labels` field.

## Consequences

- **Privacy:** conversations can contain business data and personal names. They are readable in plain text only by the owner through the API; a database dump together with `APP_KEY` discloses them (the same residual risk as ADR-0019). The notice shown to users and administrators no longer says "nothing is saved"; it says chats are saved encrypted, visible to their owner, deletable at any time.
- **Backup/restore:** the two tables join the database backup. Changing `APP_KEY` makes saved chats unreadable; they should then be deleted (`DELETE /assistant/conversations` per user, or `assistant:prune-history` with retention `0`).
- **Operations:** the stream needs a proxy that does not buffer responses; the endpoint sends `X-Accel-Buffering: no` (nginx). Behind another proxy, disable response buffering for `/api/v1/assistant/chat/stream`.
- **Rollback:** set `assistant.history_retention_days` to `0` and run `assistant:prune-history` (stops saving, deletes saved chats); the UI and `/chat` keep working. The migration is additive; `down()` drops both tables.
- **Contract change:** four conversation routes, one stream route, `conversation_id` on `/assistant/chat`, `labels` on citations, one setting. `openapi.yaml` is the source of truth for the shapes.

## Approval record

`AGENTS.md` and `docs/architecture/README.md` require architect approval for contract changes and for departing from an accepted ADR. Given by the project owner in the implementation session (branch `claude/clever-sagan-89spcf`).

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Amend ADR-0017: save each user's conversations on the server, encrypted, with owner-only access, deletion and a retention setting (item 2) | Project owner | architect | 2026-11-13 | Owner answer in the implementation session to "where are previous chats saved": "على الخادم لكل مستخدم" (on the server, per user), after describing the ChatGPT/Claude-style chat with saved conversations |
| 2 | `POST /assistant/chat/stream` and live progress events (item 3) | Project owner | architect | 2026-11-13 | Owner answer in the implementation session to "add live progress with this design": "نعم، بث حي" (yes, live stream) |
| 3 | Conversation routes, `conversation_id`, citation `labels`, setting `assistant.history_retention_days` | Project owner | architect | 2026-11-13 | Same two answers above (they are the contract these items implement) |
