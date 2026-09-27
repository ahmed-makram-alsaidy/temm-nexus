# AI Provider Gateway

> 25F · `AiGateway` + drivers in `app/Services/ControlPlane/Ai/`

## Provider-agnostic design

The platform binds to NO vendor. Drivers implement the `AiDriver` contract
(`complete`, `test`) over stable HTTP interfaces:

| Provider id | Driver | Wire format |
|---|---|---|
| `openai` | OpenAiCompatibleDriver | chat/completions (api.openai.com) |
| `openrouter` | OpenAiCompatibleDriver | chat/completions (openrouter.ai) |
| `openai_compatible` | OpenAiCompatibleDriver | chat/completions (operator base_url, HTTPS-only + SSRF-guarded) |
| `anthropic` | AnthropicDriver | /v1/messages (system as top-level field) |
| `gemini` | GeminiDriver | :generateContent (system folded as a user turn) |
| `fake` | FakeAiDriver | scripted responses for the automated suite |

## Configuration (25F.1)

`ai_provider_configs` — provider, display_name, base_url, model,
`secret_encrypted` (API key encrypted at rest; `secret_ref` allows vault
delegation), enabled, timeout, max_output_tokens, optional operator pricing,
project scope (null = global). Keys are masked everywhere; raw ciphertext
never contains plaintext (asserted by test).

## Model profiles (25F.3)

`ai_model_profiles` — logical Planner / Builder / Validator profiles that may
share or differ in model. Core logic references profiles, never concrete
model names.

## Test provider (25F.2)

`testProvider` sends a 1-token probe and returns `CONNECTED | AUTH_FAILED |
MODEL_NOT_FOUND | RATE_LIMITED | TIMEOUT | PROVIDER_ERROR`. Keys and prompt
content are never logged.

## Usage / cost (25F.4)

`ai_usage_records` — input/output tokens and request counts per profile.
Estimated cost is computed ONLY when the operator configured per-1k pricing
(and carries the currency); otherwise it stays null. No invented costs.
