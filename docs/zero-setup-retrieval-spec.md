# AskWP Zero-Setup Retrieval Spec (v1)

Status: Draft  
Last updated: 2026-02-17  
Scope: AskWP plugin retrieval and tool behavior for public WordPress sites

## 1. Why This Spec Exists

AskWP's core product promise is plug-and-play:

- install the plugin
- configure provider key/model
- chatbot works on real site content without manual content authoring

This spec exists to enforce that promise in implementation. It defines retrieval behavior that must work across typical WordPress websites, even when `post_content` is weak, stale, or incomplete.

## 2. Product Constraints

These constraints are mandatory:

1. No per-site manual content setup is required.
2. No external "training material" upload is required.
3. Public same-origin site content must be fetchable by the system itself.
4. Assistant must not ask users to paste URLs/text/screenshots for content that tools can access.
5. Behavior must remain general across WordPress themes/builders/content models.

## 3. Goals

1. Reliable evidence retrieval from public site content with zero setup.
2. Strong agentic search loops (`search -> open page -> search again`) on supported providers.
3. Graceful handling of thin/empty pages with concise labeled inference, not dead-end refusal.
4. Fast enough runtime for chat UX while maintaining source grounding.

## 4. Non-Goals

1. Solving access to private/member-only content without credentials.
2. Full web crawling beyond the site's origin.
3. Perfect extraction for every custom theme edge case on day one.
4. Heavy, site-specific manual tuning workflows.

## 5. Definitions

- `same-origin`: URLs with host equal to `site_url()` host.
- `thin content`: page text too short or semantically empty to support factual summarization.
- `retrieval evidence`: text chunks returned by tools/index used to support final answer.
- `agentic retrieval`: model chooses search steps by calling tools, not a single static context dump.

## 6. Functional Requirements

### 6.1 Zero-Setup Discovery (MUST)

System must auto-discover public same-origin content using:

1. homepage links
2. WordPress sitemaps when available (`wp-sitemap.xml` and nested sitemap indexes)
3. public post types from WordPress APIs/queries
4. recent posts/pages archives
5. menu links (when available)

No admin action beyond normal plugin setup is required.

### 6.2 Rendered-First Extraction (MUST)

For retrieval content quality, system must prefer rendered page content over raw DB fields when needed:

1. fetch page HTML with same-origin guard
2. extract readable text from `main`, `article`, or `[role=main]` first
3. fallback to body text extraction
4. strip noise (`script`, `style`, boilerplate where possible)

`post_content` remains a signal, not the only source of truth.

### 6.3 Indexing Model (MUST)

Each indexed document must keep:

1. canonical URL
2. title
3. content type (`post`, `page`, custom type when available)
4. publish/modified timestamps when available
5. cleaned text and chunked segments
6. quality flags (`thin`, extraction source, last fetched time)

### 6.4 Auto-Refresh (MUST)

Index freshness must be maintained by:

1. event-based updates on publish/update/delete hooks
2. scheduled background refresh (WP-Cron)
3. on-demand live fetch fallback when answer-time data appears stale/thin

### 6.5 Agentic Tool Contract (MUST)

Tool layer must support model-driven search loops with stable behavior:

1. `search_site(query, filters, sort, limit)` returns candidate docs/chunks + metadata
2. `get_document(id_or_url, mode)` returns structured document content
3. `get_recent(type, limit)` returns latest entries with date metadata
4. `search_in_document(id_or_url, query, limit)` returns high-signal passages
5. `fetch_live_url(url)` optional fallback for uncached/stale pages

Current tool names may be mapped internally, but capability coverage is required.

### 6.6 Evidence and Answer Policy (MUST)

Assistant behavior rules:

1. answer from retrieved evidence where available
2. include source URLs in response metadata
3. if evidence is thin/missing, provide concise labeled inference
4. do not present inferred text as direct quote
5. do not request pasted site content that tools can fetch

### 6.7 Thin Content Handling (MUST)

When target page is thin:

1. automatically search related pages before final answer
2. use recent/post archives and semantically related pages
3. if still thin, produce one concise inference and state limitation briefly

### 6.8 Security and Boundary (MUST)

1. same-origin fetch only
2. no crawling of authenticated/private pages unless explicitly supported by secure auth flow
3. respect request budgets and timeout limits
4. sanitize extracted text before prompt injection

### 6.9 Performance (SHOULD)

1. non-streaming first answer target: p95 <= 8s on local typical site
2. streaming first token target: p95 <= 2.5s when provider supports streaming
3. retrieval tool loop cap to avoid runaway calls
4. bounded crawl/index work per interval

## 7. Quality Gates (Definition of Done)

A build passes this spec only if all are true:

1. Fresh WordPress site with normal content works without retrieval setup steps.
2. Query "most recent blog post" returns real post URLs and dates.
3. Query about a specific post uses available evidence from site content.
4. Thin-page case does not dead-end; answer uses concise labeled inference.
5. Assistant does not ask user to paste content retrievable via tools.
6. Source metadata is returned for grounded answers.

## 8. Observability Requirements

System must log metrics to track retrieval quality:

1. retrieval success rate (answer contains >=1 evidence source)
2. thin-content hit rate
3. tool call count per response
4. live-fetch fallback rate
5. no-evidence inference rate
6. median and p95 retrieval latency

These metrics are required for iterative tuning without per-site manual ops.

## 9. Rollout Plan

### Phase 1: Hardening Current Retrieval

1. enforce no-paste-content behavior
2. strengthen thin-page fallback and recent-content support
3. unify tool behavior across sync/stream paths

### Phase 2: Rendered Content Index

1. add background document/chunk index from rendered pages
2. integrate index into `search_site` and `get_document`
3. add freshness metadata and targeted re-fetch

### Phase 3: Advanced Agentic Retrieval

1. add `search_in_document` and deeper multi-hop guidance
2. improve ranking with hybrid lexical + semantic signals (optional)
3. tune evidence confidence and answer style policy

## 10. Open Questions

1. Should indexed document storage live in options, transients, or custom DB tables?
2. What default crawl budget is safe on low-end shared hosting?
3. Which boilerplate removal heuristics are most reliable across major WP themes/builders?
4. Should semantic embedding search be optional or default in v2?

## 11. Implementation Rule

If a proposed change violates the zero-setup principle, it is out of spec unless explicitly approved as an optional advanced feature.
