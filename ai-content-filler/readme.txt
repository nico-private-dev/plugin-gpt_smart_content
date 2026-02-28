=== AI Content Filler ===
Contributors: nicolombe
Tags: ai, content generation, gutenberg, elementor, openai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate content for your Gutenberg and Elementor pages in one click using AI (Claude, GPT-4o, DeepSeek).

== Description ==

AI Content Filler connects your WordPress editor to leading AI APIs (Anthropic Claude, OpenAI, DeepSeek) to automatically write content for your Gutenberg blocks and Elementor widgets. Configure a client brief once, then generate contextual content for every page in one click — without leaving your editor.

**Supported editors:**

* **Gutenberg** (WordPress block editor) — dedicated sidebar panel
* **Elementor** (Free & Pro) — floating panel in the Elementor editor

**Supported Gutenberg blocks:**

* Native WordPress: Heading, Paragraph, Button, Image (alt text + caption), Quote
* Kadence Blocks: Advanced Heading, Info Box, Single Button, Testimonials, Accordion (Pane), Tabs (Tab)

**Supported Elementor widgets:**

* Heading, Text Editor

**AI providers:**

* **Anthropic Claude** — Claude Sonnet 4.5, Claude Sonnet 4, Claude Haiku 4.5, Claude Opus 4.6
* **OpenAI** — GPT-4o, GPT-4o Mini, GPT-4 Turbo, o1 Mini
* **DeepSeek** — DeepSeek Chat, DeepSeek Reasoner (R1)

**Key features:**

* Client brief (tone, audience, keywords) used as context for every generation
* Per-page custom prompt
* Language selection for generated content (French, English, Spanish, German, Italian, Portuguese, Dutch, Arabic)
* Scan page blocks/widgets and select which ones to fill
* Content injected without auto-saving — you review before publishing
* Rate limiting (1 request per 10 seconds per user)
* API key stored server-side, never exposed to the browser

**Freemium model:**

The free plan supports the most commonly used blocks and widgets. Upgrading to Pro unlocks all supported block types and higher generation limits.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin via the **Plugins** menu in WordPress
3. Go to **Settings > AI Content Filler**
4. Enter your API key (Anthropic, OpenAI, or DeepSeek)
5. Write your client brief (business context, editorial tone, target audience, keywords)
6. Open any page in the Gutenberg or Elementor editor — the AI Content Filler panel will appear

== Frequently Asked Questions ==

= Do I need Elementor Pro? =

No. The plugin works with the free version of Elementor. Heading and Text Editor widgets are available in both versions. The plugin also works independently with the native Gutenberg editor.

= Do I need Kadence Pro? =

No. The plugin supports blocks from both Kadence Blocks (free) and Kadence Pro. Kadence Blocks must be installed and activated separately.

= Where do I get an API key? =

* **Anthropic (Claude):** https://console.anthropic.com/
* **OpenAI:** https://platform.openai.com/api-keys
* **DeepSeek:** https://platform.deepseek.com/api_keys

= Does the plugin save the page automatically? =

No. Content is injected into your blocks/widgets but the page is not saved automatically. You keep full control and can review the content before saving.

= Is my API key stored securely? =

Yes. Your API key is stored in the WordPress database as an option and is only used server-side when a generation request is made. It is never sent to the browser.

= What languages are supported for content generation? =

French, English, Spanish, German, Italian, Portuguese, Dutch, and Arabic. The language setting applies to all generated content regardless of the prompt language.

= Can I use this plugin without Elementor or Kadence? =

Yes. The plugin works with the native WordPress Gutenberg editor without any additional page builder.

== External Services ==

This plugin connects to the following third-party services to generate content. An API key is required for each service you choose to use.

**Anthropic (Claude API)**
The plugin sends your client brief, custom prompt, and block/widget type information to the Anthropic API to generate content.
- Service URL: https://api.anthropic.com/v1/messages
- Privacy policy: https://www.anthropic.com/privacy
- Terms of use: https://www.anthropic.com/legal/consumer-terms

**OpenAI (ChatGPT / GPT-4o API)**
If you select OpenAI as your provider, the plugin sends the same information to the OpenAI API.
- Service URL: https://api.openai.com/v1/chat/completions
- Privacy policy: https://openai.com/privacy/
- Terms of use: https://openai.com/policies/terms-of-use/

**DeepSeek API**
If you select DeepSeek as your provider, the plugin sends the same information to the DeepSeek API.
- Service URL: https://api.deepseek.com/v1/chat/completions
- Privacy policy: https://www.deepseek.com/privacy
- Terms of use: https://www.deepseek.com/terms

**Freemius (license management)**
This plugin uses Freemius to manage licenses and plugin updates for the Pro version. Freemius may collect anonymized diagnostic data about your WordPress installation.
- Service URL: https://wp.freemius.com/
- Privacy policy: https://freemius.com/privacy/
- Terms of use: https://freemius.com/terms/

Data is only sent to these services when you actively use the plugin's content generation feature or when Freemius performs a license check. No data is collected or sent without your action.

== Screenshots ==

1. Settings page — API key, client brief, model and language selection
2. Gutenberg sidebar panel — scan blocks, select, and generate
3. Elementor floating panel — scan widgets and generate in one click

== Changelog ==

= 1.0.5 =
* Fix: Language instruction placed at the very top of the system prompt for consistent enforcement across all AI providers
* Fix: Added final language reminder at the bottom of the system prompt
* Fix: Gutenberg block content sanitization to prevent double HTML tags on reload (strip wrapping `<p>`, `<h*>` tags before applying)
* Fix: RichText blocks (Heading, Paragraph, Quote, Kadence Advanced Heading) now use replaceBlock to force React re-render and prevent content persistence issues
* Add: Kadence Blocks support (Advanced Heading, Info Box, Single Button, Testimonials, Accordion, Tabs)
* Add: DeepSeek API provider (DeepSeek Chat, DeepSeek Reasoner R1)

= 1.0.4 =
* Add: Gutenberg block editor support with dedicated sidebar panel
* Add: Support for native WordPress blocks (Heading, Paragraph, Button, Image, Quote)
* Add: Language selection for generated content (8 languages)
* Add: Per-provider API configuration (Anthropic, OpenAI)

= 1.0.3 =
* Add: OpenAI provider support (GPT-4o, GPT-4o Mini, GPT-4 Turbo, o1 Mini)
* Add: Freemium model — free plan with core features, Pro plan for advanced blocks and higher limits
* Fix: Freemius SDK loaded before plugins_loaded for correct hook registration

= 1.0.2 =
* Add: Rate limiting (1 generation request per 10 seconds per user)
* Add: Automatic retry on invalid JSON response from AI
* Fix: REST API nonce validation

= 1.0.1 =
* Fix: Elementor panel display in nested widget structures
* Fix: API key storage and retrieval

= 1.0.0 =
* Initial release
* Settings page with API key, client brief, model, temperature, max tokens
* REST API endpoint for content generation
* Floating panel in the Elementor editor
* Recursive scan of Heading and Text Editor widgets
* Rate limiting (1 request per 10 seconds per user)

== Upgrade Notice ==

= 1.0.5 =
Adds Kadence Blocks support, DeepSeek provider, and fixes Gutenberg block validation errors on page reload.
