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
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_shortcodes((string) $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $text = askwp_text_substr($text, $max_len);

    return $text;
}

/**
 * Heuristic: detect content that is too thin to answer substantive questions.
 */
function askwp_rag_is_thin_content_text($text)
{
    $clean = askwp_rag_clean_text($text, 800);
    if ($clean === '') {
        return true;
    }

    $lower = askwp_text_lower(trim($clean));
    if (in_array($lower, array('-', '--', '---', 'n/a', 'na', 'tbd', 'coming soon'), true)) {
        return true;
    }

    $alnum = preg_replace('/[^\p{L}\p{N}]+/u', '', $clean);
    if (!is_string($alnum) || strlen($alnum) < 60) {
        return true;
    }

    return false;
}

/**
 * Fetch rendered page HTML and extract readable text from main/article/body.
 */
function askwp_rag_fetch_rendered_page_text($url, $max_len, $timeout = 12)
{
    $url = esc_url_raw((string) $url);
    if ($url === '') {
        return '';
    }

    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    $url_host = wp_parse_url($url, PHP_URL_HOST);
    if ($site_host && $url_host && strtolower($site_host) !== strtolower($url_host)) {
        return '';
    }

    $response = wp_remote_get($url, array(
        'timeout' => max(3, (int) $timeout),
        'redirection' => 3,
        'headers' => array(
            'Accept' => 'text/html',
        ),
    ));

    if (is_wp_error($response)) {
        return '';
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return '';
    }

    $html = wp_remote_retrieve_body($response);
    if (!is_string($html) || trim($html) === '') {
        return '';
    }

    // Remove noisy sections first for non-DOM fallback quality.
    $html = preg_replace('/<(script|style|noscript|svg|canvas)[^>]*>.*?<\/\1>/is', ' ', $html);
    if (!is_string($html)) {
        return '';
    }

    $text = '';

    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//main | //article | //*[@role="main"]');

            if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
                $parts = array();
                foreach ($nodes as $node) {
                    $parts[] = (string) $node->textContent;
                }
                $text = implode("\n", $parts);
            } else {
                $body_nodes = $xpath->query('//body');
                if ($body_nodes instanceof DOMNodeList && $body_nodes->length > 0) {
                    $text = (string) $body_nodes->item(0)->textContent;
                }
            }
        }
    }

    if ($text === '') {
        $text = wp_strip_all_tags($html, true);
    }

    return askwp_rag_clean_text($text, $max_len);
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
 * Normalize URL for indexing/searching (same-origin, no fragment/query).
 */
function askwp_rag_normalize_same_origin_url($url)
{
    $url = esc_url_raw((string) $url);
    if ($url === '') {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $site_parts = wp_parse_url(site_url('/'));
    if (!is_array($site_parts) || empty($site_parts['host'])) {
        return '';
    }

    $site_host = strtolower((string) $site_parts['host']);
    $site_scheme = isset($site_parts['scheme']) && is_string($site_parts['scheme']) && $site_parts['scheme'] !== ''
        ? strtolower((string) $site_parts['scheme'])
        : 'https';
    $site_port = isset($site_parts['port']) ? (int) $site_parts['port'] : (($site_scheme === 'http') ? 80 : 443);

    $url_host = strtolower((string) $parts['host']);
    $url_scheme = isset($parts['scheme']) && is_string($parts['scheme']) && $parts['scheme'] !== ''
        ? strtolower((string) $parts['scheme'])
        : $site_scheme;
    $url_port = isset($parts['port']) ? (int) $parts['port'] : (($url_scheme === 'http') ? 80 : 443);

    if ($url_host !== $site_host || $url_port !== $site_port) {
        return '';
    }

    $scheme = isset($parts['scheme']) && is_string($parts['scheme']) && $parts['scheme'] !== ''
        ? $parts['scheme']
        : $site_scheme;
    if ($scheme === '') {
        $scheme = 'https';
    }

    $path = isset($parts['path']) ? (string) $parts['path'] : '/';
    if ($path === '') {
        $path = '/';
    }

    $port_segment = '';
    if (isset($site_parts['port']) && (int) $site_parts['port'] > 0) {
        $port_segment = ':' . (int) $site_parts['port'];
    }

    $normalized = $scheme . '://' . $parts['host'] . $port_segment . $path;
    if (substr($normalized, -1) !== '/' && pathinfo($normalized, PATHINFO_EXTENSION) === '') {
        $normalized .= '/';
    }

    return esc_url_raw($normalized);
}

/**
 * Generate a readable fallback title from URL path.
 */
function askwp_rag_title_from_url($url)
{
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $path = trim($path, '/');

    if ($path === '') {
        return 'Home';
    }

    $slug = wp_basename($path);
    $slug = str_replace(array('-', '_'), ' ', $slug);
    $title = ucwords(trim($slug));

    return $title !== '' ? $title : 'Page';
}

/**
 * Heuristic: identify archive-like URLs that are usually lower signal than singular content.
 */
function askwp_rag_is_archive_like_url($url)
{
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $path = trim(askwp_text_lower($path), '/');
    if ($path === '') {
        return false;
    }

    $segments = explode('/', $path);
    $first = isset($segments[0]) ? (string) $segments[0] : '';
    if (in_array($first, array('category', 'tag', 'author', 'search', 'feed'), true)) {
        return true;
    }

    if (strpos($path, '/feed/') !== false || substr($path, -5) === '/feed') {
        return true;
    }

    return false;
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
    if (askwp_rag_is_thin_content_text($content)) {
        $rendered = askwp_rag_fetch_rendered_page_text(get_permalink($post), 7000);
        if (!askwp_rag_is_thin_content_text($rendered)) {
            $content = $rendered;
        }
    }

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
 * Detect intent for "latest/recent blog post/news" style queries.
 */
function askwp_rag_is_recent_posts_intent($latest_message, $query_terms, $current_page = null)
{
    $recency_needles = array(
        'latest', 'recent', 'newest', 'most recent', 'last', 'current',
        'today', 'this week', 'this month',
        'neueste', 'neuste', 'aktuelle', 'letzte', 'zuletzt', 'neu',
    );

    $content_needles = array(
        'blog', 'post', 'posts', 'entry', 'entries', 'article', 'articles',
        'news', 'update', 'updates', 'changelog', 'release', 'releases',
        'announcement', 'announcements',
        'beitrag', 'beitraege', 'beiträge', 'artikel', 'neuigkeiten', 'blogpost',
    );

    $has_recency = askwp_rag_message_has_any($latest_message, $query_terms, $recency_needles);
    if (!$has_recency) {
        return false;
    }

    if (askwp_rag_message_has_any($latest_message, $query_terms, $content_needles)) {
        return true;
    }

    // If user asks for "latest/recent" while on a blog/news-like page, assume post intent.
    if (is_array($current_page)) {
        $page_signal = askwp_text_lower(
            (string) ($current_page['title'] ?? '') . ' ' . (string) ($current_page['url'] ?? '')
        );

        foreach (array('blog', 'news', 'updates', 'changelog') as $needle) {
            if (strpos($page_signal, $needle) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Fetch newest published entries (prefers post type "post" if enabled).
 */
function askwp_rag_recent_entries($max_results, $snippet_length)
{
    $max_results = max(1, min(10, (int) $max_results));
    $snippet_length = max(120, min(1200, (int) $snippet_length));

    $configured_types = askwp_get_option('rag_post_types', array('page', 'post'));
    if (!is_array($configured_types) || empty($configured_types)) {
        $configured_types = array('page', 'post');
    }

    $post_types = in_array('post', $configured_types, true)
        ? array('post')
        : array_values(array_unique($configured_types));

    $query_obj = new WP_Query(array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => $max_results,
        'orderby' => 'date',
        'order' => 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows' => true,
    ));

    $results = array();

    if ($query_obj->have_posts()) {
        foreach ($query_obj->posts as $index => $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }

            $title = get_the_title($post);
            $url = get_permalink($post);
            if ($title === '' || $url === '') {
                continue;
            }

            $excerpt = has_excerpt($post) ? get_the_excerpt($post) : '';
            if ($excerpt === '') {
                $excerpt = askwp_rag_extract_post_text($post, $snippet_length);
            } else {
                $excerpt = askwp_rag_clean_text($excerpt, $snippet_length);
            }

            $published = get_the_date('Y-m-d', $post);
            if ($published !== '') {
                $excerpt = trim('Published: ' . $published . '. ' . $excerpt);
            }

            $results[] = array(
                'title' => $title,
                'url' => $url,
                'snippet' => askwp_text_substr($excerpt, $snippet_length),
                // Keep deterministic recency entries visible after ranking/filtering.
                'term_hits' => max(1, 100 - (int) $index),
            );
        }
    }

    wp_reset_postdata();

    return $results;
}

function askwp_rag_site_index_option_key()
{
    return 'askwp_rag_site_index_v2';
}

function askwp_rag_site_index_ttl_seconds()
{
    return 6 * HOUR_IN_SECONDS;
}

function askwp_rag_parse_xml_locs($xml, $max_items = 100)
{
    if (!is_string($xml) || trim($xml) === '') {
        return array();
    }

    preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $matches);
    if (empty($matches[1])) {
        return array();
    }

    $items = array();
    foreach ($matches[1] as $loc) {
        $url = askwp_rag_normalize_same_origin_url(html_entity_decode((string) $loc, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($url === '') {
            continue;
        }
        $items[$url] = true;
        if (count($items) >= $max_items) {
            break;
        }
    }

    return array_keys($items);
}

function askwp_rag_fetch_sitemap_urls($max_urls = 50)
{
    $max_urls = max(1, min(200, (int) $max_urls));
    $root = askwp_rag_normalize_same_origin_url(home_url('/wp-sitemap.xml'));
    if ($root === '') {
        return array();
    }

    $urls = array();
    $queue = array($root);
    $visited = array();
    $max_sitemaps = 8;

    while (!empty($queue) && count($visited) < $max_sitemaps && count($urls) < $max_urls) {
        $sitemap_url = array_shift($queue);
        if (isset($visited[$sitemap_url])) {
            continue;
        }
        $visited[$sitemap_url] = true;

        $response = wp_remote_get($sitemap_url, array(
            'timeout' => 8,
            'redirection' => 2,
            'headers' => array('Accept' => 'application/xml,text/xml'),
        ));
        if (is_wp_error($response)) {
            continue;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            continue;
        }

        $xml = wp_remote_retrieve_body($response);
        $locs = askwp_rag_parse_xml_locs($xml, $max_urls * 2);

        foreach ($locs as $loc) {
            if (preg_match('/\.xml$/i', (string) wp_parse_url($loc, PHP_URL_PATH))) {
                if (!isset($visited[$loc]) && !in_array($loc, $queue, true)) {
                    $queue[] = $loc;
                }
                continue;
            }

            $urls[$loc] = true;
            if (count($urls) >= $max_urls) {
                break 2;
            }
        }
    }

    return array_keys($urls);
}

function askwp_rag_collect_candidate_posts($max_posts = 50)
{
    $max_posts = max(10, min(200, (int) $max_posts));

    $post_types = askwp_get_option('rag_post_types', array('page', 'post'));
    if (!is_array($post_types) || empty($post_types)) {
        $post_types = array('page', 'post');
    }

    $posts = get_posts(array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        'numberposts' => $max_posts,
        'orderby' => 'modified',
        'order' => 'DESC',
        'suppress_filters' => false,
    ));

    $by_id = array();
    foreach ($posts as $post) {
        if ($post instanceof WP_Post) {
            $by_id[(int) $post->ID] = $post;
        }
    }

    foreach (array((int) get_option('page_on_front'), (int) get_option('page_for_posts')) as $special_id) {
        if ($special_id <= 0 || isset($by_id[$special_id])) {
            continue;
        }
        $special = get_post($special_id);
        if ($special instanceof WP_Post && $special->post_status === 'publish') {
            $by_id[$special_id] = $special;
        }
    }

    return array_values($by_id);
}

function askwp_rag_collect_candidate_urls($max_urls = 100)
{
    $max_urls = max(10, min(300, (int) $max_urls));
    $urls = array();

    $home = askwp_rag_normalize_same_origin_url(home_url('/'));
    if ($home !== '') {
        $urls[$home] = true;
    }

    foreach (array((int) get_option('page_on_front'), (int) get_option('page_for_posts')) as $special_id) {
        if ($special_id <= 0) {
            continue;
        }
        $url = askwp_rag_normalize_same_origin_url(get_permalink($special_id));
        if ($url !== '') {
            $urls[$url] = true;
        }
    }

    if (function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items')) {
        $menus = wp_get_nav_menus(array('hide_empty' => true));
        if (is_array($menus)) {
            foreach ($menus as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id, array(
                    'update_post_term_cache' => false,
                ));
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    $url = askwp_rag_normalize_same_origin_url(isset($item->url) ? $item->url : '');
                    if ($url === '') {
                        continue;
                    }
                    $urls[$url] = true;
                    if (count($urls) >= $max_urls) {
                        break 2;
                    }
                }
            }
        }
    }

    if (count($urls) < $max_urls) {
        $sitemap_urls = askwp_rag_fetch_sitemap_urls($max_urls - count($urls));
        foreach ($sitemap_urls as $url) {
            $urls[$url] = true;
            if (count($urls) >= $max_urls) {
                break;
            }
        }
    }

    return array_slice(array_keys($urls), 0, $max_urls);
}

function askwp_rag_build_document_from_post($post, $max_len, &$rendered_fetches, $max_rendered_fetches)
{
    if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
        return null;
    }

    $url = askwp_rag_normalize_same_origin_url(get_permalink($post));
    if ($url === '') {
        return null;
    }

    $title = trim((string) get_the_title($post));
    if ($title === '') {
        $title = askwp_rag_title_from_url($url);
    }

    $content = askwp_rag_extract_post_text($post, $max_len);
    $is_thin = askwp_rag_is_thin_content_text($content);

    if ($is_thin && $rendered_fetches < $max_rendered_fetches) {
        $rendered = askwp_rag_fetch_rendered_page_text($url, $max_len, 8);
        $rendered_fetches++;
        if (!askwp_rag_is_thin_content_text($rendered)) {
            $content = $rendered;
            $is_thin = false;
        }
    }

    $snippet = askwp_text_substr($content, max(180, (int) askwp_get_option('rag_snippet_length', 300)));
    if ($snippet === '') {
        $snippet = $title;
    }

    $ts = strtotime((string) ($post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : $post->post_modified));
    if (!$ts) {
        $ts = time();
    }

    return array(
        'url' => $url,
        'title' => $title,
        'snippet' => $snippet,
        'text' => askwp_text_substr($content, $max_len),
        'post_id' => (int) $post->ID,
        'post_type' => (string) $post->post_type,
        'date' => (string) get_the_date('Y-m-d', $post),
        'ts' => (int) $ts,
        'is_thin' => $is_thin ? 1 : 0,
    );
}

function askwp_rag_build_document_from_url($url, $max_len, &$rendered_fetches, $max_rendered_fetches)
{
    $url = askwp_rag_normalize_same_origin_url($url);
    if ($url === '' || $rendered_fetches >= $max_rendered_fetches) {
        return null;
    }

    $text = askwp_rag_fetch_rendered_page_text($url, $max_len, 8);
    $rendered_fetches++;

    if (askwp_rag_is_thin_content_text($text)) {
        return null;
    }

    $post_id = (int) url_to_postid($url);
    $title = '';
    $post_type = '';
    $date = '';
    $ts = 0;

    if ($post_id > 0) {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $post->post_status === 'publish') {
            $title = trim((string) get_the_title($post));
            $post_type = (string) $post->post_type;
            $date = (string) get_the_date('Y-m-d', $post);
            $ts = strtotime((string) ($post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : $post->post_modified));
        }
    }

    if ($title === '') {
        $title = askwp_rag_title_from_url($url);
    }
    if (!$ts) {
        $ts = time();
    }

    return array(
        'url' => $url,
        'title' => $title,
        'snippet' => askwp_text_substr($text, max(180, (int) askwp_get_option('rag_snippet_length', 300))),
        'text' => askwp_text_substr($text, $max_len),
        'post_id' => $post_id > 0 ? $post_id : 0,
        'post_type' => $post_type,
        'date' => $date,
        'ts' => (int) $ts,
        'is_thin' => 0,
    );
}

function askwp_rag_build_site_index($force = false)
{
    $key = askwp_rag_site_index_option_key();
    $cached = get_option($key, array());

    if (
        !$force &&
        is_array($cached) &&
        !empty($cached['built_at']) &&
        !empty($cached['documents']) &&
        (time() - (int) $cached['built_at']) < askwp_rag_site_index_ttl_seconds()
    ) {
        return $cached;
    }

    $max_docs = 40;
    $max_rendered_fetches = $force ? 14 : 8;
    $max_len = 5000;

    $documents = array();
    $rendered_fetches = 0;

    $posts = askwp_rag_collect_candidate_posts($max_docs + 20);
    foreach ($posts as $post) {
        $doc = askwp_rag_build_document_from_post($post, $max_len, $rendered_fetches, $max_rendered_fetches);
        if (!is_array($doc) || empty($doc['url'])) {
            continue;
        }
        $documents[$doc['url']] = $doc;
        if (count($documents) >= $max_docs) {
            break;
        }
    }

    if (count($documents) < $max_docs) {
        $urls = askwp_rag_collect_candidate_urls(140);
        foreach ($urls as $url) {
            if (isset($documents[$url])) {
                continue;
            }
            $doc = askwp_rag_build_document_from_url($url, $max_len, $rendered_fetches, $max_rendered_fetches);
            if (!is_array($doc) || empty($doc['url'])) {
                continue;
            }
            $documents[$doc['url']] = $doc;
            if (count($documents) >= $max_docs || $rendered_fetches >= $max_rendered_fetches) {
                break;
            }
        }
    }

    $docs = array_values($documents);
    usort($docs, function ($a, $b) {
        $a_ts = isset($a['ts']) ? (int) $a['ts'] : 0;
        $b_ts = isset($b['ts']) ? (int) $b['ts'] : 0;
        if ($a_ts === $b_ts) {
            return 0;
        }
        return ($a_ts > $b_ts) ? -1 : 1;
    });

    $payload = array(
        'version' => 1,
        'built_at' => time(),
        'documents' => array_slice($docs, 0, $max_docs),
    );

    update_option($key, $payload, false);

    return $payload;
}

function askwp_rag_get_site_index($force = false)
{
    return askwp_rag_build_site_index((bool) $force);
}

function askwp_rag_search_site_index($query, $max_results, $exclude_post_id = 0)
{
    $query = askwp_rag_clean_text($query, 120);
    if ($query === '') {
        return array();
    }

    $index = askwp_rag_get_site_index(false);
    $docs = isset($index['documents']) && is_array($index['documents']) ? $index['documents'] : array();
    if (empty($docs)) {
        return array();
    }

    $max_results = max(1, min(20, (int) $max_results));
    $snippet_length = max(120, (int) askwp_get_option('rag_snippet_length', 300));
    $query_terms = askwp_rag_query_terms($query);
    $query_lower = askwp_text_lower($query);

    $results = array();

    foreach ($docs as $doc) {
        if (!is_array($doc) || empty($doc['url'])) {
            continue;
        }

        if ($exclude_post_id > 0 && isset($doc['post_id']) && (int) $doc['post_id'] === (int) $exclude_post_id) {
            continue;
        }

        $haystack = (string) ($doc['title'] ?? '') . ' ' . (string) ($doc['snippet'] ?? '') . ' ' . (string) ($doc['text'] ?? '');
        $hits = askwp_rag_count_term_hits($haystack, $query_terms);

        if ($hits < 1 && $query_lower !== '' && strpos(askwp_text_lower($haystack), $query_lower) !== false) {
            $hits = 1;
        }

        if ($hits < 1) {
            continue;
        }

        $snippet = askwp_rag_make_focus_snippet((string) ($doc['text'] ?? ''), $query_terms, $snippet_length);
        if ($snippet === '') {
            $snippet = askwp_text_substr((string) ($doc['snippet'] ?? ''), $snippet_length);
        }

        $recency_boost = 0;
        if (!empty($doc['ts']) && (time() - (int) $doc['ts']) < (45 * DAY_IN_SECONDS)) {
            $recency_boost = 1;
        }
        $quality_boost = !empty($doc['is_thin']) ? 0 : 1;
        $type_boost = in_array((string) ($doc['post_type'] ?? ''), array('post', 'page'), true) ? 1 : 0;
        $archive_penalty = askwp_rag_is_archive_like_url((string) $doc['url']) ? 2 : 0;
        $score = (int) $hits + $recency_boost + $quality_boost + $type_boost - $archive_penalty;

        if ($score < 1) {
            continue;
        }

        $results[] = array(
            'title' => (string) ($doc['title'] ?? askwp_rag_title_from_url($doc['url'])),
            'url' => esc_url_raw((string) $doc['url']),
            'snippet' => $snippet,
            'term_hits' => $score,
        );
    }

    if (empty($results)) {
        return array();
    }

    return askwp_rag_rank_search_results($results, $query_terms, $max_results);
}

/**
 * Tool-facing website search with recency-aware behavior.
 *
 * Keeps retrieval deterministic and lightweight while allowing the LLM
 * to drive multi-step exploration via search_website + get_page.
 */
function askwp_rag_tool_search($query, $exclude_post_id, $max_results)
{
    $query = askwp_rag_clean_text($query, 120);
    if ($query === '') {
        return array();
    }

    $max_results = max(1, min(12, (int) $max_results));
    $query_terms = askwp_rag_query_terms($query);
    $snippet_length = (int) askwp_get_option('rag_snippet_length', 300);

    $results = askwp_rag_search_posts($query, $exclude_post_id);
    $index_results = askwp_rag_search_site_index($query, $max_results + 4, $exclude_post_id);
    if (!empty($index_results)) {
        $results = array_merge($results, $index_results);
    }

    if (askwp_rag_is_recent_posts_intent($query, $query_terms, null)) {
        $recent_entries = askwp_rag_recent_entries(max($max_results, 3), $snippet_length);
        if (!empty($recent_entries)) {
            $results = array_merge($recent_entries, $results);
        }
    }

    return askwp_rag_rank_search_results($results, $query_terms, $max_results);
}

/**
 * Build support snippets for thin pages from related same-site content.
 *
 * @return array<int, array{title: string, url: string, snippet: string}>
 */
function askwp_rag_collect_thin_page_support($page, $seed_query, $max_items = 3)
{
    if (!is_array($page) || empty($page['url'])) {
        return array();
    }

    $max_items = max(1, min(5, (int) $max_items));
    $seed_query = askwp_rag_clean_text($seed_query, 180);
    if ($seed_query === '') {
        $seed_query = askwp_rag_clean_text((string) ($page['title'] ?? ''), 180);
    }
    if ($seed_query === '') {
        return array();
    }

    $target_url = askwp_rag_normalize_same_origin_url((string) $page['url']);
    if ($target_url === '') {
        return array();
    }

    $exclude_post_id = isset($page['post_id']) ? (int) $page['post_id'] : 0;
    $query_terms = askwp_rag_query_terms((string) ($page['title'] ?? '') . ' ' . $seed_query);
    if (empty($query_terms)) {
        $query_terms = askwp_rag_query_terms($seed_query);
    }

    $index = askwp_rag_get_site_index(false);
    $docs = isset($index['documents']) && is_array($index['documents']) ? $index['documents'] : array();
    $docs_by_url = array();
    foreach ($docs as $doc) {
        if (!is_array($doc) || empty($doc['url'])) {
            continue;
        }
        $doc_url = askwp_rag_normalize_same_origin_url((string) $doc['url']);
        if ($doc_url === '') {
            continue;
        }
        $docs_by_url[$doc_url] = $doc;
    }

    $candidate_results = askwp_rag_search_site_index($seed_query, max(8, $max_items + 5), $exclude_post_id);
    if (count($candidate_results) < ($max_items + 1)) {
        $candidate_results = array_merge(
            $candidate_results,
            askwp_rag_tool_search($seed_query, $exclude_post_id, max(8, $max_items + 5))
        );
    }

    $support = array();
    $seen_urls = array($target_url => true);

    foreach ($candidate_results as $candidate) {
        $candidate_url = askwp_rag_normalize_same_origin_url(isset($candidate['url']) ? (string) $candidate['url'] : '');
        if ($candidate_url === '' || isset($seen_urls[$candidate_url]) || askwp_rag_is_archive_like_url($candidate_url)) {
            continue;
        }
        $seen_urls[$candidate_url] = true;

        $title = '';
        $snippet = '';

        if (isset($docs_by_url[$candidate_url]) && is_array($docs_by_url[$candidate_url])) {
            $doc = $docs_by_url[$candidate_url];
            $doc_text = (string) ($doc['text'] ?? '');
            $doc_title = trim((string) ($doc['title'] ?? ''));
            $doc_snippet = (string) ($doc['snippet'] ?? '');

            if ($doc_text !== '' && !askwp_rag_is_thin_content_text($doc_text)) {
                $snippet = askwp_rag_make_focus_snippet($doc_text, $query_terms, 420);
            }

            if ($snippet === '' && $doc_snippet !== '') {
                $snippet = askwp_text_substr(askwp_rag_clean_text($doc_snippet, 500), 420);
            }

            if ($doc_title !== '') {
                $title = $doc_title;
            }
        }

        if ($snippet === '' && !empty($candidate['snippet'])) {
            $snippet = askwp_text_substr(askwp_rag_clean_text((string) $candidate['snippet'], 500), 420);
        }

        if ($snippet === '' || askwp_rag_is_thin_content_text($snippet)) {
            continue;
        }

        if ($title === '') {
            $title = trim((string) ($candidate['title'] ?? ''));
        }
        if ($title === '') {
            $title = askwp_rag_title_from_url($candidate_url);
        }

        $support[] = array(
            'title' => $title,
            'url' => $candidate_url,
            'snippet' => $snippet,
        );

        if (count($support) >= $max_items) {
            break;
        }
    }

    return $support;
}

/**
 * Build get_page tool output with fallback extraction for thin content.
 *
 * Returns array{page: array, text: string, is_thin: bool} or null.
 */
function askwp_rag_get_page_tool_payload($url, $max_len = 3000)
{
    $page = askwp_rag_resolve_page($url);
    if (!is_array($page)) {
        return null;
    }

    $post = get_post((int) $page['post_id']);
    $content = ($post instanceof WP_Post)
        ? askwp_rag_extract_post_text($post, $max_len)
        : (string) $page['content'];
    $content = askwp_rag_clean_text($content, $max_len);

    $excerpt = '';
    $published = '';
    if ($post instanceof WP_Post) {
        $published = (string) get_the_date('Y-m-d', $post);
        $raw_excerpt = has_excerpt($post) ? get_the_excerpt($post) : '';
        if ($raw_excerpt !== '') {
            $excerpt = askwp_rag_clean_text($raw_excerpt, (int) ($max_len * 0.6));
        }
    }

    $is_thin = askwp_rag_is_thin_content_text($content);

    if ($is_thin) {
        $rendered = askwp_rag_fetch_rendered_page_text($page['url'], $max_len);
        if (!askwp_rag_is_thin_content_text($rendered)) {
            $content = $rendered;
            $is_thin = false;
        }
    }

    if ($is_thin && $excerpt !== '' && !askwp_rag_is_thin_content_text($excerpt)) {
        $content = $excerpt;
        $is_thin = false;
    }

    $support_snippets = array();
    if ($is_thin) {
        $support_snippets = askwp_rag_collect_thin_page_support(
            $page,
            (string) ($page['title'] ?? ''),
            3
        );
    }

    $content_status = 'full';
    if ($is_thin && !empty($support_snippets)) {
        $content_status = 'support_enriched';
    } elseif ($is_thin) {
        $content_status = 'thin';
    }

    $lines = array(
        $page['title'],
        'URL: ' . $page['url'],
    );
    if ($published !== '') {
        $lines[] = 'Published: ' . $published;
    }
    $lines[] = 'Content status: ' . $content_status;

    $text = implode("\n", $lines) . "\n\n" . $content;

    if (!empty($support_snippets)) {
        $text .= "\n\nSupporting evidence from related pages on this site:";
        foreach ($support_snippets as $item) {
            $text .= "\n- " . $item['title'];
            $text .= "\n  URL: " . $item['url'];
            $text .= "\n  Excerpt: " . $item['snippet'];
        }
        $text .= "\n\nThe target page body is minimal. Use the supporting evidence above as the primary basis for your answer and do not lead with access-limit disclaimers.";
    } elseif ($is_thin) {
        $text .= "\n\nThe page has very little body text and no strong supporting excerpts were found. Provide one concise title-based inference (label it as an inference), then stop.";
    }

    return array(
        'page' => $page,
        'text' => $text,
        'is_thin' => $is_thin,
    );
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
        $archive_penalty = askwp_rag_is_archive_like_url((string) ($item['url'] ?? '')) ? 1 : 0;
        $item['term_hits'] = max(0, max($computed_hits, $existing_hits) - $archive_penalty);

        if ($item['term_hits'] < 1) {
            continue;
        }

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
 * by page title, recency-aware boost for blog/news queries, rank results,
 * match FAQ, collect sources.
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
        $current_is_thin = askwp_rag_is_thin_content_text((string) $current_page['content']);
        $current_page['is_thin'] = $current_is_thin ? 1 : 0;
        $current_page['focus_snippet'] = askwp_rag_make_focus_snippet(
            $current_page['content'],
            $query_terms,
            900
        );
        if ($current_is_thin) {
            $current_page['support_snippets'] = askwp_rag_collect_thin_page_support(
                $current_page,
                (string) ($current_page['title'] ?? ''),
                2
            );
        } else {
            $current_page['support_snippets'] = array();
        }
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

    // 4a. Merge rendered site-index results for zero-setup retrieval quality.
    $index_results = askwp_rag_search_site_index($search_query, $max_results + 3, $exclude_post_id);
    if (count($index_results) < $max_results && is_array($current_page) && !empty($current_page['title'])) {
        $index_results = array_merge(
            $index_results,
            askwp_rag_search_site_index((string) $current_page['title'], $max_results + 2, $exclude_post_id)
        );
    }
    if (!empty($index_results)) {
        $search_results = askwp_rag_rank_search_results(
            array_merge($search_results, $index_results),
            $query_terms,
            $max_results
        );
    }

    // 4b. For "latest/recent blog posts" intents, inject deterministic recent entries.
    if (askwp_rag_is_recent_posts_intent($latest_message, $query_terms, $current_page)) {
        $recent_entries = askwp_rag_recent_entries(max($max_results, 3), $snippet_length);
        if (!empty($recent_entries)) {
            $search_results = askwp_rag_rank_search_results(
                array_merge($recent_entries, $search_results),
                $query_terms,
                $max_results
            );
        }
    }

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
        $page_priority = !empty($page['is_thin']) ? 'MEDIUM' : 'HIGH';
        $chunks[] = "[CURRENT_PAGE | PRIORITY: " . $page_priority . "]\nTitle: " . $page['title'] . "\nURL: " . $page['url'] . "\nExcerpt: " . $page_excerpt;

        if (!empty($page['is_thin']) && !empty($page['support_snippets']) && is_array($page['support_snippets'])) {
            $support_chunks = array();
            foreach ($page['support_snippets'] as $item) {
                if (!is_array($item) || empty($item['url'])) {
                    continue;
                }
                $support_chunks[] = "- " . (string) ($item['title'] ?? askwp_rag_title_from_url((string) $item['url'])) . "\n  URL: " . (string) $item['url'] . "\n  Snippet: " . (string) ($item['snippet'] ?? '');
            }
            if (!empty($support_chunks)) {
                $chunks[] = "[CURRENT_PAGE_SUPPORT | PRIORITY: HIGH]\nCurrent page body is minimal; use these related snippets for substantive answers:\n" . implode("\n", $support_chunks);
            }
        }
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

function askwp_rag_schedule_index_refresh()
{
    if (wp_next_scheduled('askwp_rag_refresh_site_index_event')) {
        return;
    }

    wp_schedule_event(time() + 300, 'hourly', 'askwp_rag_refresh_site_index_event');
}
add_action('init', 'askwp_rag_schedule_index_refresh');

function askwp_rag_refresh_site_index_event_handler()
{
    askwp_rag_get_site_index(true);
}
add_action('askwp_rag_refresh_site_index_event', 'askwp_rag_refresh_site_index_event_handler');

function askwp_rag_invalidate_site_index($post_id = 0)
{
    if ($post_id > 0 && function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
        return;
    }

    delete_option(askwp_rag_site_index_option_key());
}
add_action('save_post', 'askwp_rag_invalidate_site_index', 20);
add_action('deleted_post', 'askwp_rag_invalidate_site_index', 20);
add_action('wp_update_nav_menu', 'askwp_rag_invalidate_site_index', 20);
