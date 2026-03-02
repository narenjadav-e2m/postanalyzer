<?php

namespace PostAnalyzer\API;

defined('ABSPATH') || exit;

class Gemini_Helper {

    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    /**
     * @return array|\WP_Error  Array of slug suggestions, or WP_Error on failure
     */
    public function generate_url_suggestions(array $context, int $retry = 2)
    {
        // Always use the saved key from Settings
        $api_key = Settings::get_active_api_key();
        if (empty($api_key)) {
            return new \WP_Error('missing_api_key', 'Gemini API key not found in settings.');
        }

        $prompt = $this->build_prompt($context);

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.3,
                'maxOutputTokens' => 400,
            ],
        ];

        $url = add_query_arg(['key' => $api_key], $this->endpoint);

        $attempt = 0;

        do {
            $attempt++;

            $response = wp_remote_post($url, [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                if ($attempt > $retry) {
                    return new \WP_Error('gemini_http_error', $response->get_error_message());
                }
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code < 200 || $code >= 300) {
                if ($attempt > $retry) {
                    return new \WP_Error(
                        'gemini_api_error',
                        'Gemini API request failed with status ' . $code,
                        ['response_body' => $body]
                    );
                }
                continue;
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                if ($attempt > $retry) {
                    return new \WP_Error('gemini_bad_json', 'Gemini returned invalid JSON.', ['raw' => $body]);
                }
                continue;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = $this->parse_ai_json($text);

            if (!empty($parsed['suggestions']) && is_array($parsed['suggestions'])) {
                // normalize slugs (optional, but helpful)
                $slugs = array_values(array_filter(array_map('sanitize_title', $parsed['suggestions'])));
                $slugs = array_values(array_unique($slugs));

                if (!empty($slugs)) {
                    return $slugs;
                }
            }

            if ($attempt > $retry) {
                return new \WP_Error(
                    'gemini_no_suggestions',
                    'Gemini did not return valid suggestions.',
                    ['raw_text' => $text]
                );
            }

        } while ($attempt <= $retry);

        return new \WP_Error('gemini_unknown', 'Unknown error while generating suggestions.');
    }

    private function build_prompt(array $context): string
    {
        $title    = isset($context['title']) ? wp_strip_all_tags((string) $context['title']) : '';
        $content  = isset($context['content']) ? wp_strip_all_tags((string) $context['content']) : '';
        $keywords = isset($context['keywords']) ? (array) $context['keywords'] : [];

        $keywords_str = implode(', ', array_map('sanitize_text_field', $keywords));

        return
            "You are an SEO assistant.\n" .
            "Generate 5 SEO-friendly URL slugs for a WordPress post.\n\n" .
            "Rules:\n" .
            "- output ONLY valid JSON\n" .
            "- no markdown, no backticks\n" .
            "- JSON format: {\"suggestions\":[\"slug-one\",\"slug-two\",...]} \n" .
            "- slugs must be lowercase, hyphen-separated, no special characters\n" .
            "- each suggestion must be unique\n\n" .
            "Title: {$title}\n" .
            "Keywords: {$keywords_str}\n" .
            "Content (excerpt): " . mb_substr($content, 0, 700);
    }

    private function parse_ai_json(string $text): array
    {
        $text = trim($text);

        // Remove markdown fences if Gemini adds them anyway
        $text = preg_replace('/^```(json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $json = json_decode($text, true);
        return is_array($json) ? $json : [];
    }
}
