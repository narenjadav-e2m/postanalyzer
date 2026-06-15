# PostAnalyzer

> Automated SEO, accessibility, and content QA audits for WordPress posts — powered by AI.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.3+ |
| PHP | 8.1+ |
| Node.js | 18+ (for development) |

## Installation

1. Upload the `postanalyzer` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins → Installed Plugins**
3. Go to **Post Analyzer → Settings** and configure your AI platform API key
4. Select a post and click **Analyze Post**

## AI Platforms Supported

| Platform | Model | Notes |
|---|---|---|
| **Groq** | llama-3.3-70b-versatile | Recommended — fast & free tier |
| **OpenAI** | gpt-4o-mini | Reliable, paid |
| **Gemini** | gemini-2.0-flash | Google AI Studio free tier |

## Development Setup

```bash
cd postanalyzer
npm install
npm run build        # One-time production build
npm run watch        # Watch mode during development
```

## Project Structure

```
postanalyzer/
├── postanalyzer.php         # Plugin bootstrap & constants
├── uninstall.php            # Clean uninstall hook
├── includes/
│   └── Plugin.php           # Core singleton (hooks, menu, assets)
├── api/
│   ├── Analyze_Post.php     # POST /analyze-post
│   ├── Posts.php            # GET  /posts
│   ├── Users.php            # GET  /users
│   ├── Settings.php         # GET/POST /get-settings, /save-settings, /validate-key
│   └── AI_Helper.php        # AI platform abstraction (Gemini, OpenAI, Groq)
├── src/                     # React source (Vite + Tailwind v4)
│   ├── main.jsx
│   ├── api.js               # Centralized REST client
│   ├── PostAnalyzer.jsx     # Root component (useReducer state machine)
│   ├── useFancybox.jsx      # Fancybox lightbox hook
│   └── components/
│       ├── PostSelector.jsx
│       ├── ReportView.jsx
│       ├── SettingsModal.jsx
│       ├── LoadingSpinner.jsx
│       ├── EmptyState.jsx
│       ├── ErrorBanner.jsx
│       ├── MetaRow.jsx
│       ├── ImageMeta.jsx
│       └── cards/
│           ├── BasicInfo.jsx
│           ├── SEOData.jsx
│           ├── FeaturedImage.jsx
│           ├── AttachedImages.jsx
│           └── SuggestionsCard.jsx
└── build/                   # Compiled assets (committed or CI-generated)
```

## Extending

### Add a custom post type to the analyzer

```php
add_filter( 'postanalyzer_post_types', function( $types ) {
    $types[] = 'my_custom_post_type';
    return $types;
} );
```

### Hook into analysis results

Future: `postanalyzer_after_analyze` action will fire with `$post_id` and `$response`.

## REST API

All endpoints are under `/wp-json/postanalyzer/v1/`.

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/analyze-post` | Editor+ | Run full analysis on a post |
| `GET` | `/posts` | Editor+ | List analyzable posts |
| `GET` | `/users` | Editor+ | List eligible authors |
| `GET` | `/get-settings` | Editor+ | Retrieve current settings |
| `POST` | `/save-settings` | Admin | Save AI platform & API key |
| `POST` | `/validate-key` | Admin | Validate an API key live |

## Changelog

### 2.0.0
- Complete architecture refactor for scalability
- New centralized `api.js` REST client
- `useReducer` state machine in main component
- SEO score (0–100) in analysis response
- Show/hide API key toggle in settings
- Platform key indicator dots in settings
- Animated loading step progress in spinner
- Dismissible error banner
- Missing alt text badge on image thumbnails
- File size display for media library images
- `postanalyzer_post_types` filter for custom post type support
- Pagination headers on `/posts` endpoint
- `/validate-key` REST endpoint
- `uninstall.php` for clean plugin removal
- PHP 8.1 version gate with admin notice

### 1.0.0
- Initial release
