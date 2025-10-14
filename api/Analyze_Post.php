<?php

namespace PostAnalyzer\API;

defined('ABSPATH') || exit;

/**
 * REST endpoint that analyzes a post and returns metadata, SEO info, images, and suggestions.
 */
class Analyze_Post
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('postanalyzer/v1', '/analyze-post', [
            'methods'  => 'POST',
            'callback' => [$this, 'analyze_post'],
            'permission_callback' => function () {
                return current_user_can('edit_posts'); // allow editors/admins
            }
        ]);
    }

    public function analyze_post($request)
    {
        $params = $request->get_json_params();

        // Accept either post_id (preferred) or url (fallback)
        $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;

        if ($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                return rest_ensure_response(['error' => 'Invalid post ID.']);
            }
            $url = get_the_permalink($post_id);
            if (! $url) {
                return rest_ensure_response(['error' => 'Could not resolve post URL.']);
            }
        }

        if (!$url) {
            return rest_ensure_response(['error' => 'No URL or post_id provided']);
        }

        // Title
        $title = get_the_title($post_id) ?: '';
        $url = get_the_permalink($post_id) ?: '';
        $excerpt = get_the_excerpt($post_id) ?: '';

        // SEO meta tags
        $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        $seo_description = get_post_meta($post_id, 'rank_math_description', true);
        $seo_keywords = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $robots_meta  = get_post_meta($post_id, 'rank_math_robots', true);

        // Handle possible serialized value from older versions
        if (is_string($robots_meta)) {
            $robots = array_map('trim', explode(',', $robots_meta));
        } else {
            $robots = is_array($robots_meta) ? $robots_meta : [];
        }
        $is_noindex  = in_array('noindex', $robots, true) ? 'yes' : 'no';
        $is_nofollow = in_array('nofollow', $robots, true) ? 'yes' : 'no';

        // Basic post info heuristics
        $author = $post_id ? get_the_author_meta('display_name', $post->post_author) : null;
        $published = $post_id ? get_the_date('F j, Y g:i A', $post_id) : null;
        $updated = $post_id ? get_the_modified_date('F j, Y g:i A', $post_id) : null;

        // Word count
        // Get post content
        $content = get_post_field('post_content', $post_id);

        // Remove shortcodes, strip HTML tags, and trim
        $clean_content = trim(strip_tags(strip_shortcodes($content)));

        // Count words
        $word_count = str_word_count($clean_content);

        // Featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);

        if ($thumbnail_id) {
            $featured_url  = wp_get_attachment_url($thumbnail_id);
            $metadata      = wp_get_attachment_metadata($thumbnail_id);

            $featured_image = [
                'id'          => $thumbnail_id,
                'src'         => $featured_url,
                'alt'         => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                'title'       => get_the_title($thumbnail_id),
                'caption'     => wp_get_attachment_caption($thumbnail_id),
                'description' => get_post_field('post_content', $thumbnail_id),
                'filename'    => basename($featured_url),
                'width'       => $metadata['width'] ?? null,
                'height'      => $metadata['height'] ?? null,
                'type'        => 'media-library',
                'mime_type'   => get_post_mime_type($thumbnail_id),
                'sizes'       => $metadata['sizes'] ?? [],
                'image_meta'  => isset($metadata['image_meta']) ? $metadata['image_meta'] : null,
                'upload_date' => get_the_date('F j, Y g:i A', $thumbnail_id),
            ];
        } else {
            $featured_image = null;
        }


        //attahced images
        $attached_images = $this->fetch_attached_images($post_id)['unique_images'];

        // SEO checks
        $seo_issues = [];
        if (empty($seo_title)) $seo_issues[] = 'Missing SEO title';
        else {
            $len = mb_strlen($seo_title);
            if ($len < 30) $seo_issues[] = 'SEO title is short (<30 chars)';
            if ($len > 70) $seo_issues[] = 'SEO title is long (>70 chars)';
        }
        if (empty($seo_description)) $seo_issues[] = 'Missing meta description';
        else {
            $len = mb_strlen($seo_description);
            if ($len < 120) $seo_issues[] = 'Meta description is short (<120 chars)';
            if ($len > 320) $seo_issues[] = 'Meta description is long (>320 chars)';
        }

        // URL suggestions
        $base_for_slug = $seo_title ?: $title ?: $url;
        $slug_candidate = sanitize_title($base_for_slug);
        $url_suggestions = [];
        if ($slug_candidate) {
            $parsed = parse_url($url);
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            $url_suggestions[] = "{$scheme}://{$host}/{$slug_candidate}";
            $url_suggestions[] = "{$scheme}://{$host}/{$slug_candidate}-1";
            $url_suggestions[] = "{$scheme}://{$host}/{$slug_candidate}-optimized";
        }

        // AI suggestions
        $ai_suggestions = [];
        if (!empty($seo_issues)) {
            $ai_suggestions[] = 'Improve SEO title/description: make the title descriptive (50-60 chars) and meta description 150-160 chars with target keywords.';
        }
        if (empty($featured_image) && count($attached_images) === 0) {
            $ai_suggestions[] = 'No images found: add a featured image and descriptive alt text to improve accessibility and SEO.';
        } else {
            foreach ($attached_images as $a) {
                if (empty($a['alt'])) {
                    $ai_suggestions[] = "Image {$a['filename']} is missing alt text.";
                    if (count($ai_suggestions) > 8) break;
                }
            }
        }

        $response = [
            'url' => $url,
            'title' => $title,
            'author' => $author,
            'published_date' => $published,
            'updated_date' => $updated,
            'categories' => get_the_category_list(', ', '', $post_id) ?: '',
            'tags' => get_the_tag_list('', ', ', '', $post_id) ?: '',
            'word_count' => $word_count,
            'seo' => [
                'title' => $seo_title,
                'description' => $seo_description,
                'keywords' => $seo_keywords ? array_map(fn($k) => trim($k), explode(',', $seo_keywords)) : [],
                'issues' => $seo_issues
            ],
            'featured_image' => $featured_image,
            'attached_images' => $attached_images,
            'url_suggestions' => $url_suggestions,
            'ai_suggestions' => $ai_suggestions,
        ];

        $response = \PostAnalyzer\Plugin::instance()->recursive_html_entity_decode($response);

        return rest_ensure_response($response);
    }

    private function fetch_attached_images($post_id)
    {
        // Get post content
        $post = get_post($post_id);
        $post_content = $post->post_content;

        // First, get attached images (parent-child relationship)
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_parent'    => $post_id,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_mime_type' => 'image',
        ]);

        $attached_images = [];
        $attachment_urls = []; // Track attachment URLs to avoid duplicates

        foreach ($attachments as $attachment) {
            $image_data = $this->get_image_data_from_attachment($attachment->ID);
            if ($image_data) {
                $attached_images[] = $image_data;
                $attachment_urls[] = $image_data['src'];
            }
        }

        // Now parse content for embedded images
        $content_images = [];

        // Extract images from content using regex
        preg_match_all('/<img[^>]+>/i', $post_content, $img_matches);

        if (!empty($img_matches[0])) {
            foreach ($img_matches[0] as $img_tag) {
                // Extract src
                preg_match('/src=["\'](.*?)["\']/i', $img_tag, $src_match);
                $src = isset($src_match[1]) ? $src_match[1] : '';

                if (empty($src)) continue;

                // Skip if this image is already in attachments
                $is_attachment = false;
                foreach ($attachment_urls as $att_url) {
                    if ($this->urls_match($src, $att_url)) {
                        $is_attachment = true;
                        break;
                    }
                }

                if ($is_attachment) continue;

                // Try to get attachment ID from URL using WordPress function
                $attachment_id = attachment_url_to_postid($src);

                if ($attachment_id) {
                    // Get data from database
                    $image_data = $this->get_image_data_from_attachment($attachment_id);
                    if ($image_data) {
                        $content_images[] = $image_data;
                    }
                } else {
                    // If we can't find in database, create minimal entry
                    $content_images[] = [
                        'id'          => null,
                        'src'         => esc_url_raw($src),
                        'alt'         => '',
                        'title'       => '',
                        'caption'     => '',
                        'description' => '',
                        'filename'    => basename(parse_url($src, PHP_URL_PATH)),
                        'width'       => null,
                        'height'      => null,
                        'type'        => 'external'
                    ];
                }
            }
        }

        // Also check for Gutenberg image blocks
        if (has_blocks($post_content)) {
            $blocks = parse_blocks($post_content);
            $this->process_blocks_for_images($blocks, $content_images);
        }

        // Combine all images (attached + embedded)
        $all_images = array_merge($attached_images, $content_images);

        // Remove featured image if it exists
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $all_images = array_filter($all_images, function ($img) use ($featured_image_id) {
                return $img['id'] != $featured_image_id;
            });
        }

        // Remove duplicates based on ID (primary) or src (fallback)
        $unique_images = [];
        $seen_ids = [];
        $seen_srcs = [];

        foreach ($all_images as $image) {
            // Skip if we've seen this ID
            if ($image['id'] && in_array($image['id'], $seen_ids)) {
                continue;
            }

            // Skip if we've seen this URL (normalized)
            $normalized_src = $this->normalize_image_url($image['src']);
            if (in_array($normalized_src, $seen_srcs)) {
                continue;
            }

            $unique_images[$image['id']] = $image;
            if ($image['id']) {
                $seen_ids[] = $image['id'];
            }
            $seen_srcs[] = $normalized_src;
        }

        return ['unique_images' => $unique_images, 'seen_srcs' => $seen_srcs];
    }

    /**
     * Get complete image data from attachment ID
     */
    private function get_image_data_from_attachment($attachment_id)
    {
        // Verify it's a valid attachment
        if (get_post_type($attachment_id) !== 'attachment') {
            return null;
        }

        // Get attachment post object
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return null;
        }

        // Get metadata using WordPress functions
        $metadata = wp_get_attachment_metadata($attachment_id);
        $url = wp_get_attachment_url($attachment_id);
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $file_path = get_attached_file($attachment_id);

        // Get image size info
        $upload_dir = wp_upload_dir();
        $file_info = pathinfo($file_path);

        return [
            'id'          => $attachment_id,
            'src'         => $url,
            'alt'         => $alt_text ?: '',
            'title'       => $attachment->post_title ?: '',
            'caption'     => $attachment->post_excerpt ?: '',
            'description' => $attachment->post_content ?: '',
            'filename'    => basename($file_path),
            'width'       => isset($metadata['width']) ? $metadata['width'] : null,
            'height'      => isset($metadata['height']) ? $metadata['height'] : null,
            'type'        => 'media-library',
            'mime_type'   => $attachment->post_mime_type,
            'file_size'   => file_exists($file_path) ? filesize($file_path) : null,
            'upload_date' => $attachment->post_date,
            'image_meta'  => isset($metadata['image_meta']) ? $metadata['image_meta'] : null,
            'sizes'       => isset($metadata['sizes']) ? array_keys($metadata['sizes']) : []
        ];
    }

    /**
     * Process Gutenberg blocks for images
     */
    private function process_blocks_for_images($blocks, &$content_images)
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/image') {
                $attachment_id = isset($block['attrs']['id']) ? $block['attrs']['id'] : null;

                // If no ID in attributes, try to extract from innerHTML
                if (!$attachment_id && !empty($block['innerHTML'])) {
                    preg_match('/wp-image-(\d+)/', $block['innerHTML'], $matches);
                    if (isset($matches[1])) {
                        $attachment_id = intval($matches[1]);
                    }
                }

                if ($attachment_id) {
                    $image_data = $this->get_image_data_from_attachment($attachment_id);
                    if ($image_data && !in_array($image_data['id'], array_column($content_images, 'id'))) {
                        $content_images[] = $image_data;
                    }
                } else {
                    // Try to get URL from innerHTML and then get attachment ID
                    preg_match('/src=["\'](.*?)["\']/i', $block['innerHTML'], $src_match);
                    if (isset($src_match[1])) {
                        $attachment_id = attachment_url_to_postid($src_match[1]);
                        if ($attachment_id) {
                            $image_data = $this->get_image_data_from_attachment($attachment_id);
                            if ($image_data && !in_array($image_data['id'], array_column($content_images, 'id'))) {
                                $content_images[] = $image_data;
                            }
                        }
                    }
                }
            } elseif ($block['blockName'] === 'core/gallery') {
                // Process gallery images
                if (isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
                    foreach ($block['attrs']['ids'] as $attachment_id) {
                        $image_data = $this->get_image_data_from_attachment($attachment_id);
                        if ($image_data && !in_array($image_data['id'], array_column($content_images, 'id'))) {
                            $content_images[] = $image_data;
                        }
                    }
                }

                // Also check innerBlocks for newer gallery format
                if (isset($block['innerBlocks'])) {
                    $this->process_blocks_for_images($block['innerBlocks'], $content_images);
                }
            } elseif ($block['blockName'] === 'core/media-text' && isset($block['attrs']['mediaId'])) {
                // Handle media-text blocks
                $attachment_id = $block['attrs']['mediaId'];
                $image_data = $this->get_image_data_from_attachment($attachment_id);
                if ($image_data && !in_array($image_data['id'], array_column($content_images, 'id'))) {
                    $content_images[] = $image_data;
                }
            } elseif (isset($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                // Recursively process inner blocks (columns, groups, etc.)
                $this->process_blocks_for_images($block['innerBlocks'], $content_images);
            }
        }
    }

    /**
     * Check if two URLs refer to the same image
     */
    private function urls_match($url1, $url2)
    {
        $normalized1 = $this->normalize_image_url($url1);
        $normalized2 = $this->normalize_image_url($url2);

        return $normalized1 === $normalized2;
    }

    /**
     * Normalize image URL by removing size suffixes and query strings
     */
    private function normalize_image_url($url)
    {
        // Remove size suffix (-300x200)
        $url = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $url);

        // Remove query strings
        $parsed = parse_url($url);
        $url = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];

        return $url;
    }
}
