<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RAG (Retrieval-Augmented Generation) module for AskWP.
 *
 * Resolves the current page, searches WordPress content, matches FAQ pairs,
 * and assembles a context block for the LLM system prompt.
 *
 * Dependencies: askwp_text_substr(), askwp_text_lower() from security.php;
 *               askwp_get_option() from askwp.php.
 */

/**
 * Strip shortcodes, HTML tags, collapse whitespace, and truncate.
 */
function askwp_rag_clean_text($text, $max_len)
{
    $text = strip_shortcodes((string) $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $text = askwp_text_substr($text, $max_len);

    return $text;
}

/**
 * Extract readable text from a WP_Post, preferring rendered content.
 */
function askwp_rag_extract_post_text($post, $max_len)
{
    if (!$post instanceof WP_Post) {
        return '';
    }

    $raw_content = (string) $post->post_content;
    $rendered_content = apply_filters('the_content', $raw_content);

    $clean_rendered = askwp_rag_clean_text($rendered_content, $max_len);
    if ($clean_rendered !== '') {
        return $clean_rendered;
    }

    return askwp_rag_clean_text($raw_content, $max_len);
}

/**
 * Normalize a URL path: decode, collapse slashes, strip WP home prefix.
 */
function askwp_rag_normalize_url_path($path)
{
    $path = rawurldecode((string) $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = trim((string) $path, '/');

    if ($path === '') {
        return '';
    }

    $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = trim($home_path, '/');

    if ($home_path !== '') {
        if ($path === $home_path) {
            return '';
        }

        if (strpos($path, $home_path . '/') === 0) {
            $path = substr($path, strlen($home_path) + 1);
            $path = trim((string) $path, '/');
        }
    }

    return $path;
}

/**
 * Count how many search terms appear in a text string (case-insensitive).
 */
function askwp_rag_count_term_hits($text, $terms)
{
    if (!is_array($terms) || empty($terms)) {
        return 0;
    }

    $haystack = askwp_text_lower((string) $text);
    $hits = 0;

    foreach ($terms as $term) {
        if ($term === '') {
            continue;
        }
        if (strpos($haystack, askwp_text_lower($term)) !== false) {
            $hits++;
        }
    }

    return $hits;
}

/**
 * Extract sentences that contain search terms, blending leading context if sparse.
 */
function askwp_rag_make_focus_snippet($text, $terms, $max_len)
{
    $text = askwp_rag_clean_text($text, 4000);
    if ($text === '') {
        return '';
    }

    if (!is_array($terms) || empty($terms)) {
        return askwp_text_substr($text, $max_len);
    }

    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text);
    if (!is_array($sentences) || empty($sentences)) {
        return askwp_text_substr($text, $max_len);
    }

    $picked = array();
    foreach ($sentences as $sentence) {
        $sentence = trim((string) $sentence);
        if ($sentence === '') {
            continue;
        }

        if (askwp_rag_count_term_hits($sentence, $terms) > 0) {
            $picked[] = $sentence;
        }

        if (strlen(implode(' ', $picked)) >= $max_len) {
            break;
        }
    }

    if (empty($picked)) {
        return askwp_text_substr($text, $max_len);
    }

    $candidate = implode(' ', $picked);

    // If keyword matches are sparse, blend in leading context to keep broad coverage.
    if (strlen($candidate) < (int) ($max_len * 0.65)) {
        $leading = askwp_text_substr($text, $max_len);
        $candidate = trim($candidate . ' ' . $leading);
    }

    return askwp_text_substr($candidate, $max_len);
}

/**
 * Resolve a page URL to a WP_Post with extracted content.
 *
 * Returns array{post_id, title, url, content} or null.
 */
function askwp_rag_resolve_page($page_url)
{
    $page_url = esc_url_raw((string) $page_url);
    if ($page_url === '') {
        return null;
    }

    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    $url_host = wp_parse_url($page_url, PHP_URL_HOST);

    if ($site_host && $url_host && strtolower($site_host) !== strtolower($url_host)) {
        return null;
    }

    $post_id = (int) url_to_postid($page_url);

    if ($post_id <= 0) {
        $path = askwp_rag_normalize_url_path((string) wp_parse_url($page_url, PHP_URL_PATH));

        if ($path === '') {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                $post_id = $front_page_id;
            }
        } else {
            $normalized_url = trailingslashit(home_url('/' . $path));
            $post_id = (int) url_to_postid($normalized_url);
            $post = null;

            if ($post_id > 0) {
                $post = get_post($post_id);
            }

            if (!$post instanceof WP_Post) {
                $post = get_page_by_path($path, OBJECT, array('page', 'post'));
            }

            if (!$post instanceof WP_Post) {
                $slug = sanitize_title(wp_basename($path));
                if ($slug !== '') {
                    $slug_posts = get_posts(array(
                        'name' => $slug,
                        'post_type' => array('page', 'post'),
                        'post_status' => 'publish',
                        'numberposts' => 1,
                    ));

                    if (!empty($slug_posts) && $slug_posts[0] instanceof WP_Post) {
                        $post = $slug_posts[0];
                    }
                }
            }

            if ($post instanceof WP_Post) {
                $post_id = (int) $post->ID;
            }
        }
    }

    if ($post_id <= 0) {
        return null;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
        return null;
    }

    $content = askwp_rag_extract_post_text($post, 7000);

    return array(
        'post_id' => (int) $post_id,
        'title' => get_the_title($post),
        'url' => get_permalink($post),
        'content' => $content,
    );
}

/**
 * Search published posts via WP_Query. Post types are configurable.
 */
function askwp_rag_search_posts($query, $exclude_post_id)
{
    $query = askwp_rag_clean_text($query, 120);
    if ($query === '') {
        return array();
    }

    $post_types = askwp_get_option('rag_post_types', array('page', 'post'));
    if (!is_array($post_types) || empty($post_types)) {
        $post_types = array('page', 'post');
    }

    $args = array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => 6,
        's' => $query,
        'ignore_sticky_posts' => true,
    );

    if ($exclude_post_id > 0) {
        $args['post__not_in'] = array((int) $exclude_post_id);
    }

    $results = array();
    $query_obj = new WP_Query($args);

    if ($query_obj->have_posts()) {
        foreach ($query_obj->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }

            $excerpt = has_excerpt($post) ? get_the_excerpt($post) : '';
            if ($excerpt === '') {
                $excerpt = askwp_rag_extract_post_text($post, 300);
            } else {
                $excerpt = askwp_rag_clean_text($excerpt, 300);
            }

            $results[] = array(
                'title' => get_the_title($post),
                'url' => get_permalink($post),
                'snippet' => $excerpt,
            );
        }
    }

    wp_reset_postdata();

    return $results;
}

/**
 * Parse Q:/A: formatted FAQ text into structured pairs.
 */
function askwp_rag_parse_faq($faq_raw)
{
    $faq_raw = trim((string) $faq_raw);
    if ($faq_raw === '') {
        return array();
    }

    $blocks = preg_split('/\R{2,}/', $faq_raw);
    $pairs = array();

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $question = '';
        $answer = '';

        if (preg_match('/Q:\s*(.+?)\R+A:\s*(.+)/is', $block, $matches)) {
            $question = trim($matches[1]);
            $answer = trim($matches[2]);
        } else {
            $lines = preg_split('/\R/', $block);
            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'Q:') === 0) {
                    $question = trim(substr($line, 2));
                }
                if (stripos($line, 'A:') === 0) {
                    $answer = trim(substr($line, 2));
                }
            }
        }

        if ($question === '' || $answer === '') {
            continue;
        }

        $pairs[] = array(
            'question' => askwp_rag_clean_text($question, 240),
            'answer' => askwp_rag_clean_text($answer, 500),
        );
    }

    return $pairs;
}

/**
 * Extract meaningful search terms from a query string.
 *
 * Words shorter than 3 characters are already excluded by the regex.
 * The stopword list covers common English and German function words.
 */
function askwp_rag_query_terms($query)
{
    $query = askwp_text_lower((string) $query);
    preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $matches);

    $stopwords = array(
        // English — common words that pollute search queries
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had',
        'her', 'was', 'one', 'our', 'out', 'has', 'have', 'from', 'they', 'been',
        'said', 'each', 'which', 'their', 'will', 'other', 'about', 'many', 'then',
        'them', 'these', 'some', 'would', 'into', 'more', 'than', 'with', 'that',
        'this', 'what', 'there', 'could', 'should', 'would', 'might', 'shall',
        'tell', 'told', 'know', 'knew', 'think', 'thought', 'want', 'need',
        'like', 'just', 'also', 'very', 'much', 'really', 'please', 'thanks',
        'does', 'doing', 'done', 'were', 'being', 'those', 'here', 'when',
        'where', 'while', 'whom', 'whose', 'make', 'made', 'give', 'gave',
        'show', 'look', 'come', 'came', 'take', 'took', 'going', 'gone',
        'your', 'mine', 'ours', 'yours', 'myself', 'itself', 'something',
        'anything', 'everything', 'nothing', 'someone', 'anyone', 'everyone',
        'every', 'most', 'such', 'only', 'same', 'still', 'well', 'back',
        'even', 'good', 'great', 'right', 'long', 'little', 'never', 'always',
        'often', 'maybe', 'sure', 'yeah', 'okay', 'keep', 'thing', 'things',
        'latest', 'recent', 'current', 'new', 'last',
        // German
        'der', 'die', 'das', 'und', 'oder', 'aber', 'eine', 'einer', 'einen',
        'einem', 'ist', 'sind', 'wie', 'was', 'wer', 'kann', 'mit', 'für', 'zum',
        'zur', 'von', 'bei', 'den', 'dem', 'des', 'ich', 'wir', 'sie', 'ihr',
        'ein', 'auf', 'haben', 'habe', 'gibt', 'nicht', 'auch', 'noch', 'nur',
        'schon', 'wenn', 'denn', 'weil', 'nach', 'über', 'können', 'möchte',
        'bitte', 'welche', 'welcher', 'welches', 'sagen', 'zeigen', 'geben',
        'machen', 'neueste', 'letzte', 'aktuelle',
    );

    $terms = array();
    foreach ($matches[0] as $term) {
        if (in_array($term, $stopwords, true)) {
            continue;
        }
        $terms[$term] = true;
    }

    return array_keys($terms);
}

/**
 * Check if a message or its extracted terms contain any of the given needles.
 */
function askwp_rag_message_has_any($message, $terms, $needles)
{
    $message = askwp_text_lower((string) $message);
    $terms_lookup = array();

    if (is_array($terms)) {
        foreach ($terms as $term) {
            $terms_lookup[askwp_text_lower((string) $term)] = true;
        }
    }

    foreach ((array) $needles as $needle) {
        $needle = askwp_text_lower((string) $needle);
        if ($needle === '') {
            continue;
        }

        if (strpos($message, $needle) !== false || isset($terms_lookup[$needle])) {
            return true;
        }
    }

    return false;
}

/**
 * Rank and deduplicate search results by term hits, breaking ties by title length.
 */
function askwp_rag_rank_search_results($results, $query_terms, $max_results)
{
    $by_url = array();

    foreach ((array) $results as $item) {
        if (empty($item['url'])) {
            continue;
        }

        $computed_hits = askwp_rag_count_term_hits(
            (string) ($item['title'] ?? '') . ' ' . (string) ($item['snippet'] ?? ''),
            (array) $query_terms
        );
        $existing_hits = isset($item['term_hits']) ? (int) $item['term_hits'] : 0;
        $item['term_hits'] = max($computed_hits, $existing_hits);

        $url = (string) $item['url'];
        if (!isset($by_url[$url]) || ((int) $item['term_hits'] > (int) $by_url[$url]['term_hits'])) {
            $by_url[$url] = $item;
        }
    }

    $ranked = array_values($by_url);
    usort($ranked, function ($a, $b) {
        $a_hits = (int) ($a['term_hits'] ?? 0);
        $b_hits = (int) ($b['term_hits'] ?? 0);
        if ($a_hits !== $b_hits) {
            return ($a_hits > $b_hits) ? -1 : 1;
        }

        $a_title_len = strlen((string) ($a['title'] ?? ''));
        $b_title_len = strlen((string) ($b['title'] ?? ''));
        if ($a_title_len === $b_title_len) {
            return 0;
        }

        return ($a_title_len > $b_title_len) ? -1 : 1;
    });

    return array_slice($ranked, 0, $max_results);
}

/**
 * Match FAQ pairs by query terms, returning the best-scoring matches.
 */
function askwp_rag_match_faq($query, $faq_raw, $max_results)
{
    $pairs = askwp_rag_parse_faq($faq_raw);
    if (empty($pairs)) {
        return array();
    }

    $terms = askwp_rag_query_terms($query);
    if (empty($terms)) {
        return array_slice($pairs, 0, $max_results);
    }

    $scored = array();

    foreach ($pairs as $pair) {
        $haystack = askwp_text_lower($pair['question'] . ' ' . $pair['answer']);
        $score = 0;

        foreach ($terms as $term) {
            if (strpos($haystack, $term) !== false) {
                $score++;
            }
        }

        if ($score > 0) {
            $pair['score'] = $score;
            $scored[] = $pair;
        }
    }

    if (empty($scored)) {
        return array();
    }

    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return 0;
        }
        return ($a['score'] > $b['score']) ? -1 : 1;
    });

    $scored = array_slice($scored, 0, $max_results);

    foreach ($scored as &$item) {
        unset($item['score']);
    }
    unset($item);

    return $scored;
}

/**
 * Build a search query string from extracted terms, optionally incorporating the page title.
 */
function askwp_rag_build_search_query($latest_message, $query_terms, $current_page)
{
    $compact_terms = array();

    if (is_array($query_terms) && !empty($query_terms)) {
        $compact_terms = array_slice($query_terms, 0, 5);
    }

    // For section-overview questions, the page title is often the strongest signal.
    if (is_array($current_page) && !empty($current_page['title'])) {
        $title_term = askwp_text_lower(askwp_rag_clean_text($current_page['title'], 60));
        if ($title_term !== '' && !in_array($title_term, $compact_terms, true)) {
            if (strpos(askwp_text_lower((string) $latest_message), $title_term) !== false) {
                array_unshift($compact_terms, $title_term);
            }
        }
    }

    $compact_terms = array_values(array_unique(array_filter($compact_terms)));
    if (!empty($compact_terms)) {
        return implode(' ', array_slice($compact_terms, 0, 5));
    }

    return askwp_rag_clean_text($latest_message, 120);
}

/**
 * Merge and deduplicate two sets of search results, sorting by term_hits.
 */
function askwp_rag_merge_search_results($primary_results, $fallback_results, $max_results)
{
    $all = array_merge(
        is_array($primary_results) ? $primary_results : array(),
        is_array($fallback_results) ? $fallback_results : array()
    );

    if (empty($all)) {
        return array();
    }

    $by_url = array();
    foreach ($all as $item) {
        if (empty($item['url'])) {
            continue;
        }
        $by_url[$item['url']] = $item;
    }

    $deduped = array_values($by_url);
    usort($deduped, function ($a, $b) {
        $a_hits = isset($a['term_hits']) ? (int) $a['term_hits'] : 0;
        $b_hits = isset($b['term_hits']) ? (int) $b['term_hits'] : 0;
        if ($a_hits === $b_hits) {
            return 0;
        }

        return ($a_hits > $b_hits) ? -1 : 1;
    });

    return array_slice($deduped, 0, $max_results);
}

/**
 * Build the full RAG context for a chat turn.
 *
 * Simplified flow: resolve page, primary search from terms, optional fallback
 * by page title, rank results, match FAQ, collect sources.
 *
 * Returns array{latest_message, query_terms, current_page, search_results, faq_results, sources}.
 */
function askwp_rag_build_context($page_url, $latest_message, $faq_raw)
{
    $query_terms = askwp_rag_query_terms($latest_message);
    $snippet_length = (int) askwp_get_option('rag_snippet_length', 300);
    $max_results = (int) askwp_get_option('rag_max_results', 4);
    $max_faq = (int) askwp_get_option('rag_max_faq', 2);

    $context = array(
        'latest_message' => askwp_rag_clean_text($latest_message, 220),
        'query_terms' => $query_terms,
        'current_page' => null,
        'search_results' => array(),
        'faq_results' => array(),
        'sources' => array(),
    );

    // 1. Resolve current page.
    $current_page = askwp_rag_resolve_page($page_url);
    if (is_array($current_page)) {
        $current_page['focus_snippet'] = askwp_rag_make_focus_snippet(
            $current_page['content'],
            $query_terms,
            900
        );
        $context['current_page'] = $current_page;
        $context['sources'][] = array(
            'title' => $current_page['title'],
            'url' => $current_page['url'],
        );
    }

    // 2. Primary search from extracted terms.
    $exclude_post_id = is_array($current_page) ? (int) $current_page['post_id'] : 0;
    $search_query = askwp_rag_build_search_query($latest_message, $query_terms, $current_page);
    $search_results_primary = askwp_rag_search_posts($search_query, $exclude_post_id);

    // 3. Fallback search by page title if primary results are sparse.
    $fallback_search_results = array();
    if (
        count($search_results_primary) < $max_results &&
        is_array($current_page) &&
        !empty($current_page['title'])
    ) {
        $fallback_search_results = askwp_rag_search_posts($current_page['title'], $exclude_post_id);
    }

    // 4. Rank and deduplicate.
    $search_results = askwp_rag_rank_search_results(
        array_merge($search_results_primary, $fallback_search_results),
        $query_terms,
        $max_results
    );

    // Strip zero-hit results when at least one result has positive hits.
    $has_positive_hits = false;
    foreach ($search_results as $result) {
        if ((int) ($result['term_hits'] ?? 0) > 0) {
            $has_positive_hits = true;
            break;
        }
    }

    if ($has_positive_hits) {
        $search_results = array_values(array_filter($search_results, static function ($item) {
            return (int) ($item['term_hits'] ?? 0) > 0;
        }));
    }

    // Re-generate snippets at configured length.
    foreach ($search_results as &$result) {
        if (!empty($result['snippet'])) {
            $result['snippet'] = askwp_text_substr($result['snippet'], $snippet_length);
        }
    }
    unset($result);

    $context['search_results'] = $search_results;

    foreach ($search_results as $result) {
        $context['sources'][] = array(
            'title' => $result['title'],
            'url' => $result['url'],
        );
    }

    // 5. Match FAQ.
    $faq_results = askwp_rag_match_faq($latest_message, $faq_raw, $max_faq);
    $context['faq_results'] = $faq_results;

    if (!empty($faq_results)) {
        $context['sources'][] = array(
            'title' => 'FAQ',
            'url' => site_url('/'),
        );
    }

    // Dedupe sources by URL.
    $unique = array();
    $clean_sources = array();

    foreach ($context['sources'] as $source) {
        if (empty($source['url']) || isset($unique[$source['url']])) {
            continue;
        }

        $unique[$source['url']] = true;
        $clean_sources[] = array(
            'title' => (string) $source['title'],
            'url' => esc_url_raw((string) $source['url']),
        );
    }

    $context['sources'] = $clean_sources;

    return $context;
}

/**
 * Format a context array into labeled text blocks for the system prompt.
 */
function askwp_rag_context_to_text($context)
{
    $chunks = array();

    if (!empty($context['latest_message'])) {
        $chunks[] = "[USER_QUESTION]\n" . $context['latest_message'];
    }

    if (!empty($context['query_terms'])) {
        $chunks[] = "[KEY_TERMS]\n" . implode(', ', $context['query_terms']);
    }

    if (!empty($context['current_page'])) {
        $page = $context['current_page'];
        $page_excerpt = !empty($page['focus_snippet']) ? $page['focus_snippet'] : $page['content'];
        $chunks[] = "[CURRENT_PAGE | PRIORITY: HIGH]\nTitle: " . $page['title'] . "\nURL: " . $page['url'] . "\nExcerpt: " . $page_excerpt;
    }

    if (!empty($context['search_results'])) {
        $search_chunks = array();
        foreach ($context['search_results'] as $item) {
            $search_chunks[] = "- " . $item['title'] . "\n  URL: " . $item['url'] . "\n  Hits: " . (isset($item['term_hits']) ? (int) $item['term_hits'] : 0) . "\n  Snippet: " . $item['snippet'];
        }
        $chunks[] = "[WP_SEARCH | PRIORITY: MEDIUM]\n" . implode("\n", $search_chunks);
    }

    if (!empty($context['faq_results'])) {
        $faq_chunks = array();
        foreach ($context['faq_results'] as $faq) {
            $faq_chunks[] = "Q: " . $faq['question'] . "\nA: " . $faq['answer'];
        }
        $chunks[] = "[FAQ_MATCHES | PRIORITY: LOW]\n" . implode("\n\n", $faq_chunks);
    }

    return implode("\n\n", $chunks);
}
