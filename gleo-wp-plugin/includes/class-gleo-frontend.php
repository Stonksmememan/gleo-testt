<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gleo_Frontend {

	public function __construct() {
		// Virtual /llms.txt endpoint
		add_action( 'template_redirect', array( $this, 'serve_llms_txt' ) );

		// JSON-LD injection into <head>
		add_action( 'wp_head', array( $this, 'inject_json_ld' ), 1 );

		// AI overview (not shown to visitors — meta + JSON-LD + crawler-only markup).
		add_action( 'wp_head', array( $this, 'inject_ai_overview_head' ), 3 );

		// /llms.txt is served dynamically (see serve_llms_txt); this <head> link lets crawlers and the Gleo scanner detect it.
		add_action( 'wp_head', array( $this, 'inject_llms_link' ), 2 );

		// Front-end styles for Gleo-injected content blocks
		add_action( 'wp_head', array( $this, 'inject_content_styles' ), 5 );

		// When loading a post inside the Gleo admin preview iframe, force full theme + block CSS
		// so the page does not render as unstyled plain HTML (common with block themes / split bundles).
		add_action( 'wp_enqueue_scripts', array( $this, 'force_full_frontend_assets' ), 100 );

		// Preview iframe: body class + readable fallback layout; optional style-queue logging for admins.
		add_filter( 'body_class', array( $this, 'preview_body_class' ) );
		add_action( 'wp_head', array( $this, 'inject_preview_frame_fallback' ), 2 );
		add_action( 'wp_print_styles', array( $this, 'maybe_log_preview_styles' ), 99999 );

		// REST endpoints for applying fixes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Optional: append Allow rules for common AI crawlers (enabled via Gleo apply action).
		add_filter( 'robots_txt', array( $this, 'append_ai_crawler_allows_to_robots_txt' ), 99, 2 );

		// Block themes often wrap the post in a 3-column row with only the middle column filled.
		// Redistribute those inner blocks across left / center / right (parse_blocks) so the row isn’t empty on the sides.
		add_filter( 'the_content', array( $this, 'filter_rebalance_three_column_rows' ), 8 );
		add_filter( 'the_content', array( $this, 'strip_legacy_visible_ai_overview' ), 7 );
		add_filter( 'the_content', array( $this, 'append_ai_only_overview_markup' ), 99 );
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_rebalance_columns' ), 99, 2 );
	}

	/**
	 * For ?gleo_iframe=1 (Gleo live preview), ensure global styles and block library load.
	 */
	public function force_full_frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flags for public post view
		$gleo_preview = ! empty( $_GET['gleo_iframe'] ) || ! empty( $_GET['gleo_cb'] );
		if ( ! $gleo_preview ) {
			return;
		}
		if ( function_exists( 'wp_enqueue_global_styles' ) ) {
			wp_enqueue_global_styles();
		}
		wp_enqueue_style( 'wp-block-library' );
		// Classic themes: main stylesheet is sometimes skipped in edge iframe loads.
		if ( is_child_theme() && ! wp_style_is( 'gleo-theme-parent', 'enqueued' ) ) {
			wp_enqueue_style(
				'gleo-theme-parent',
				get_template_directory_uri() . '/style.css',
				array(),
				wp_get_theme( get_template() )->get( 'Version' )
			);
		}
		if ( ! wp_style_is( 'gleo-theme-root', 'enqueued' ) ) {
			$deps = is_child_theme() ? array( 'gleo-theme-parent' ) : array();
			wp_enqueue_style( 'gleo-theme-root', get_stylesheet_uri(), $deps, wp_get_theme()->get( 'Version' ) );
		}
		// Load combined block CSS instead of tiny split chunks that optimizers sometimes drop for iframe navigations.
		add_filter( 'should_load_separate_core_block_assets', '__return_false', 99 );

		if ( function_exists( 'wp_enqueue_classic_theme_styles' ) ) {
			wp_enqueue_classic_theme_styles();
		}
		foreach ( array( 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $style_handle ) {
			if ( wp_style_is( $style_handle, 'registered' ) && ! wp_style_is( $style_handle, 'enqueued' ) ) {
				wp_enqueue_style( $style_handle );
			}
		}
	}

	/**
	 * Mark front-end requests loaded in the Gleo preview iframe (for fallback CSS).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function preview_body_class( $classes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flags
		if ( ! empty( $_GET['gleo_iframe'] ) || ! empty( $_GET['gleo_cb'] ) ) {
			$classes[] = 'gleo-preview-context';
		}
		return $classes;
	}

	/**
	 * Minimal typography/layout when theme CSS is deferred or missing in iframe loads.
	 */
	public function inject_preview_frame_fallback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['gleo_iframe'] ) && empty( $_GET['gleo_cb'] ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS only
		echo <<<'GLEO_CSS'
<style id="gleo-preview-fallback">
/* Readable fallback background only — typography comes from the theme. */
body.gleo-preview-context { background-color: #f8fafc; color: #0f172a; }
body.gleo-preview-context a { color: inherit; }
body.gleo-preview-context nav a,
body.gleo-preview-context header a,
body.gleo-preview-context .site-header a {
  color: #f8fafc !important;
}
/* Block / classic themes often cap “content width”; in the Gleo iframe we want full layout width. */
body.gleo-preview-context {
  --wp--style--global--content-size: 100% !important;
  --wp--style--global--wide-size: 100% !important;
}
body.gleo-preview-context .wp-site-blocks,
body.gleo-preview-context main,
body.gleo-preview-context .wp-block-post-content,
body.gleo-preview-context .entry-content {
  max-width: none !important;
  width: 100% !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
}
body.gleo-preview-context .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
  max-width: none !important;
}
body.gleo-preview-context .alignwide,
body.gleo-preview-context .alignfull {
  max-width: none !important;
  width: 100% !important;
}
body.gleo-preview-context #page,
body.gleo-preview-context .site,
body.gleo-preview-context .container,
body.gleo-preview-context .site-content {
  max-width: none !important;
  width: 100% !important;
}
</style>

GLEO_CSS;
	}

	/**
	 * Log enqueued style handles when gleo_preview_debug=1 and user is an admin (check debug.log).
	 */
	public function maybe_log_preview_styles() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['gleo_iframe'] ) && empty( $_GET['gleo_cb'] ) ) {
			return;
		}
		if ( empty( $_GET['gleo_preview_debug'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wp_styles;
		if ( ! ( $wp_styles instanceof WP_Styles ) ) {
			return;
		}
		$queued = array();
		foreach ( (array) $wp_styles->queue as $handle ) {
			$queued[] = $handle;
		}
		error_log( '[GLEO preview] Style queue (' . count( $queued ) . '): ' . implode( ', ', $queued ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Detect the site's primary accent color from theme settings.
	 * Tries block-theme global styles first, then classic theme mods.
	 */
	private function get_theme_accent_color() {
		// Block themes: read theme.json color palette
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( ! empty( $palette ) && is_array( $palette ) ) {
				foreach ( $palette as $swatch ) {
					if ( isset( $swatch['slug'] ) && in_array( $swatch['slug'], array( 'primary', 'accent', 'vivid-cyan-blue' ), true ) ) {
						$c = sanitize_hex_color( $swatch['color'] ?? '' );
						if ( $c ) return $c;
					}
				}
				// Fall back to first non-white/black color in the palette
				foreach ( $palette as $swatch ) {
					$c = sanitize_hex_color( $swatch['color'] ?? '' );
					if ( $c && ! in_array( strtolower( $c ), array( '#ffffff', '#fff', '#000000', '#000' ), true ) ) {
						return $c;
					}
				}
			}
		}
		// Classic themes: check common theme mods
		foreach ( array( 'accent_color', 'primary_color' ) as $mod ) {
			$c = sanitize_hex_color( get_theme_mod( $mod, '' ) );
			if ( $c ) return $c;
		}
		// Last resort: header text color
		$h = get_header_textcolor();
		if ( $h && 'blank' !== $h ) return '#' . ltrim( $h, '#' );
		return '#3b82f6'; // Gleo default blue
	}

	/**
	 * Convert a 6-digit hex color and alpha value into rgba() notation.
	 */
	private function hex_to_rgba( $hex, $alpha ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) return "rgba(59,130,246,{$alpha})";
		return sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
	}

	/**
	 * Detect the site background color for adaptive text contrast.
	 */
	private function get_site_background_color() {
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles = wp_get_global_styles( array( 'color' ) );
			if ( ! empty( $styles['background'] ) ) {
				$c = sanitize_hex_color( $styles['background'] );
				if ( $c ) return $c;
			}
		}
		$bg = get_theme_mod( 'background_color', 'ffffff' );
		return '#' . ltrim( $bg, '#' );
	}

	/**
	 * Return appropriate text color (dark or light) based on background luminance.
	 */
	private function get_adaptive_text_color( $bg_hex ) {
		$hex = ltrim( $bg_hex, '#' );
		if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		if ( strlen( $hex ) !== 6 ) return '#1e293b';
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		// Relative luminance (WCAG)
		$r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
		$g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
		$b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
		$luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		return $luminance > 0.4 ? '#1e293b' : '#f1f5f9';
	}

	/**
	 * Output CSS for all Gleo-injected content blocks.
	 * Uses CSS custom properties so JS can always override colours based on the
	 * element's actual rendered background — PHP cannot reliably detect dark sections.
	 */
	public function inject_content_styles() {
		if ( ! is_singular( array( 'post', 'page' ) ) ) return;
		$accent      = $this->get_theme_accent_color();
		$accent_bg   = $this->hex_to_rgba( $accent, '0.06' );
		$accent_mid  = $this->hex_to_rgba( $accent, '0.16' );
		$accent_soft = $this->hex_to_rgba( $accent, '0.12' );
		?>
<style id="gleo-content-styles">
/* AI overview — in HTML for crawlers only; never shown to visitors */
.gleo-ai-only {
  position: absolute !important;
  width: 1px !important;
  height: 1px !important;
  padding: 0 !important;
  margin: -1px !important;
  overflow: hidden !important;
  clip: rect(0, 0, 0, 0) !important;
  clip-path: inset(50%) !important;
  white-space: nowrap !important;
  border: 0 !important;
}
.gleo-ai-only[hidden] {
  display: block !important;
}

/* Headings injected by Gleo “structure” fix — same body stack + scale as article copy (no random serif/size jumps) */
.entry-content h2.wp-block-heading.gleo-section-heading,
main h2.wp-block-heading.gleo-section-heading,
.wp-site-blocks h2.wp-block-heading.gleo-section-heading {
	font-family: var(--wp--preset--font-family--body, var(--wp--preset--font-family--heading, inherit));
	font-size: clamp(1.12rem, 1.02rem + 0.45vw, 1.35rem);
	font-weight: 700;
	letter-spacing: -0.02em;
	line-height: 1.35;
	color: var(--wp--preset--color--contrast, var(--wp--preset--color--foreground, inherit));
	margin-top: clamp(2rem, 5vw, 2.75rem);
	margin-bottom: clamp(0.85rem, 2vw, 1.15rem);
	max-width: 100%;
}

/* Inside column layouts, themes often force accent-colored or centered headings — match body copy. */
.entry-content .wp-block-columns .wp-block-column h2.wp-block-heading.gleo-section-heading,
main .wp-block-columns .wp-block-column h2.wp-block-heading.gleo-section-heading {
	color: var(--wp--preset--color--contrast, var(--wp--preset--color--foreground, #1e293b));
	text-align: left;
}

/* First block after a Gleo section H2: same reading size as paragraphs (fixes huge list vs tiny body) */
.entry-content h2.gleo-section-heading + .wp-block-list,
.entry-content h2.gleo-section-heading + ul.wp-block-list,
.entry-content h2.gleo-section-heading + ol.wp-block-list,
.entry-content h2.gleo-section-heading + .wp-block-paragraph {
	font-family: var(--wp--preset--font-family--body, inherit) !important;
	font-size: var(--wp--preset--font-size--medium, var(--wp--preset--font-size--normal, 1.0625rem)) !important;
	line-height: 1.7 !important;
}
.entry-content h2.gleo-section-heading + .wp-block-list li,
.entry-content h2.gleo-section-heading + ul.wp-block-list li,
.entry-content h2.gleo-section-heading + ol.wp-block-list li {
	font-size: inherit !important;
	line-height: inherit !important;
	margin-bottom: 0.65em;
}

/* Extra vertical space after Gleo-generated lists so the next paragraph is not glued on */
.entry-content h2.gleo-section-heading + .wp-block-list,
.entry-content h2.gleo-section-heading + ul.wp-block-list,
.entry-content h2.gleo-section-heading + ol.wp-block-list {
	margin-bottom: 1.35em;
}
/* ───────────────────────────────────────────────────────────────────────
   Gleo content blocks — clean, modern, theme-aware, fully responsive.
   Uses CSS custom properties so JS can adapt colors per-element based on
   the actual rendered background (light/dark sections).
   ─────────────────────────────────────────────────────────────────────── */
:where(.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block, .gleo-opening-summary-wrap, .gleo-expert-quote) {
  --gc-text:        #0f172a;
  --gc-muted:       #64748b;
  --gc-subtle:      #94a3b8;
  --gc-border:      #e5e7eb;
  --gc-border-soft: #eef0f3;
  --gc-card:        #ffffff;
  --gc-surface:     #f8fafc;
  --gc-hover:       #f1f5f9;
  --gc-accent:      <?php echo esc_attr( $accent ); ?>;
  --gc-accent-bg:   <?php echo esc_attr( $accent_bg ); ?>;
  --gc-accent-mid:  <?php echo esc_attr( $accent_mid ); ?>;
  --gc-accent-soft: <?php echo esc_attr( $accent_soft ); ?>;
  --gc-shadow:      0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.04);
  --gc-radius:      14px;
}

/* ── Shared base ───────────────────────────────────────────────────────── */
.gleo-faq-wrap,
.gleo-stats-callout,
.gleo-table-block {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  color: var(--gc-text);
  line-height: 1.6;
  margin: 2em 0;
  clear: both;
  box-sizing: border-box;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
.gleo-faq-wrap *,
.gleo-stats-callout *,
.gleo-table-block * { box-sizing: border-box; }

/* ── FAQ accordion ─────────────────────────────────────────────────────── */
.gleo-faq-wrap > h2 {
  font-size: clamp(1.05rem, 0.95rem + 0.35vw, 1.25rem);
  font-weight: 600;
  letter-spacing: -0.01em;
  margin: 0 0 0.65em;
  color: var(--gc-text);
  line-height: 1.35;
}
.gleo-faq-accordion {
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  overflow: hidden;
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
}
.gleo-faq-item { border-bottom: 1px solid var(--gc-border-soft); }
.gleo-faq-item:last-child { border-bottom: none; }
.gleo-faq-q {
  width: 100%;
  margin: 0;
  padding: 16px 18px;
  background: transparent;
  border: 0;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 14px;
  cursor: pointer;
  text-align: left;
  font: inherit;
  font-size: clamp(0.92rem, 0.85rem + 0.2vw, 1rem);
  font-weight: 600;
  line-height: 1.45;
  color: var(--gc-text);
  transition: background 0.15s ease, color 0.15s ease;
}
.gleo-faq-q:hover { background: var(--gc-hover); }
.gleo-faq-q:focus-visible {
  outline: 2px solid var(--gc-accent);
  outline-offset: -2px;
}
.gleo-faq-q::after {
  content: '';
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  border-radius: 999px;
  background:
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/10px 2px no-repeat,
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/2px 10px no-repeat,
    var(--gc-accent-soft);
  transition: transform 0.25s ease, background-size 0.25s ease;
}
.gleo-faq-item.gleo-open .gleo-faq-q {
  color: var(--gc-text);
  background: var(--gc-accent-soft);
}
.gleo-faq-item.gleo-open .gleo-faq-q::after {
  background:
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/10px 2px no-repeat,
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/0 10px no-repeat,
    var(--gc-accent-soft);
  transform: rotate(180deg);
}
.gleo-faq-a {
  max-height: 0;
  overflow: hidden;
  padding: 0 18px;
  font-size: 0.93rem;
  line-height: 1.65;
  color: var(--gc-muted);
  transition: max-height 0.3s ease, padding 0.3s ease;
}
.gleo-faq-a > p { margin: 0; }
.gleo-faq-item.gleo-open .gleo-faq-a {
  max-height: 800px;
  padding: 0 18px 18px;
  color: var(--gc-text);
  background: var(--gc-card);
}

/* ── Sources & References block ─────────────────────────────────────────── */
.gleo-sources-block {
  border: 1px solid var(--gc-border);
  border-left: 4px solid var(--gc-accent);
  border-radius: var(--gc-radius);
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
  padding: 22px 24px 20px;
  margin: 1.75em 0;
}
.gleo-sources-heading {
  font-size: clamp(1.05rem, 0.95rem + 0.35vw, 1.25rem) !important;
  font-weight: 700 !important;
  letter-spacing: -0.01em !important;
  margin: 0 0 0.7em !important;
  color: var(--gc-text) !important;
  line-height: 1.35 !important;
}
.gleo-sources-heading::before {
  content: '';
  display: inline-block;
  width: 18px;
  height: 18px;
  margin-right: 8px;
  vertical-align: -3px;
  background-color: var(--gc-accent);
  -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/%3E%3Cpath d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/%3E%3C/svg%3E") no-repeat center / contain;
  mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/%3E%3Cpath d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/%3E%3C/svg%3E") no-repeat center / contain;
}
.gleo-sources-list {
  margin: 0;
  padding-left: 1.4em;
  list-style: decimal;
}
.gleo-sources-list li {
  padding: 4px 0;
  font-size: 0.95rem;
  line-height: 1.55;
  color: var(--gc-text);
}
.gleo-sources-list li a {
  color: var(--gc-accent);
  text-decoration: underline;
  text-underline-offset: 2px;
  word-break: break-all;
}
.gleo-sources-list li a:hover { text-decoration: none; }
/* External link indicator */
.gleo-sources-list li a::after {
  content: ' ↗';
  font-size: 0.8em;
  opacity: 0.7;
}

/* ── Data Table ────────────────────────────────────────────────────────── */
.gleo-table-block {
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
  overflow: hidden;
  width: 100%;
  max-width: 100%;
  margin-left: 0;
  margin-right: 0;
}
.gleo-table-block > h3 {
  font-size: clamp(0.95rem, 0.88rem + 0.25vw, 1.05rem);
  font-weight: 700;
  letter-spacing: -0.005em;
  margin: 0;
  padding: 14px 18px;
  border-bottom: 1px solid var(--gc-border-soft);
  color: var(--gc-text);
  background: var(--gc-surface);
}
.gleo-table-scroll {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.gleo-data-table {
  width: 100%;
  min-width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 0.92rem;
  background: var(--gc-card);
}
.gleo-data-table thead th {
  padding: 14px 18px;
  font-weight: 700;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--gc-text);
  background: var(--gc-surface);
  border-bottom: 2px solid var(--gc-border);
  white-space: normal;
  line-height: 1.35;
  vertical-align: top;
}
.gleo-data-table tbody td {
  padding: 16px 18px;
  font-size: 0.95rem;
  line-height: 1.6;
  color: var(--gc-text);
  border-bottom: 1px solid var(--gc-border-soft);
  vertical-align: top;
}
.gleo-data-table tbody tr { min-height: 3.25rem; }
.gleo-data-table tbody tr:last-child td { border-bottom: none; }
.gleo-data-table tbody tr:hover td { background: var(--gc-hover); }

/* Mobile / narrow-container fallback: flatten the table into label/value cards */
@media (max-width: 480px) {
  .gleo-data-table thead { display: none; }
  .gleo-data-table tbody, .gleo-data-table tr, .gleo-data-table td { display: block; width: 100%; }
  .gleo-data-table tr {
    padding: 10px 14px;
    border-bottom: 1px solid var(--gc-border-soft);
  }
  .gleo-data-table tr:last-child { border-bottom: none; }
  .gleo-data-table td {
    padding: 4px 0;
    border: 0;
    font-size: 0.92rem;
  }
  .gleo-data-table td[data-label]::before {
    content: attr(data-label);
    display: block;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gc-muted);
    margin-bottom: 2px;
  }
}

/* Column layouts: left-aligned copy so bullets and multi-column blocks don’t “float” in the center. */
.entry-content .wp-block-columns,
main .wp-block-columns {
  align-items: flex-start;
}
.entry-content .wp-block-columns .wp-block-column,
main .wp-block-columns .wp-block-column {
  text-align: left;
  min-width: 0;
}
.entry-content .wp-block-columns .wp-block-column :is(p, h1, h2, h3, h4, ul, ol),
main .wp-block-columns .wp-block-column :is(p, h1, h2, h3, h4, ul, ol) {
  text-align: left;
}
.entry-content .wp-block-columns .wp-block-column :is(ul, ol).wp-block-list,
main .wp-block-columns .wp-block-column :is(ul, ol).wp-block-list {
  list-style-position: outside;
  padding-left: 1.35em;
  margin-left: 0;
}

/* Column consistency for AI-generated feature blocks — roomier cards, less “thin strip” */
.entry-content .wp-block-columns .wp-block-column > :where(div, section, article) {
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  background: var(--gc-card);
  padding: 20px 20px 22px;
}
.entry-content .wp-block-columns .wp-block-column > :where(div, section, article) > *:first-child { margin-top: 0; }
.entry-content .wp-block-columns .wp-block-column > :where(div, section, article) > *:last-child { margin-bottom: 0; }
.entry-content .wp-block-columns .wp-block-column [style*="height"],
.entry-content .wp-block-columns .wp-block-column [style*="min-height"] {
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
}
.entry-content .wp-block-columns .wp-block-column h2,
.entry-content .wp-block-columns .wp-block-column h3 {
  font-size: clamp(1.02rem, 0.95rem + 0.35vw, 1.28rem);
  line-height: 1.28;
  margin-bottom: 0.55em;
  color: var(--wp--preset--color--contrast, var(--wp--preset--color--foreground, #1e293b));
}
.entry-content .wp-block-columns.is-layout-flex {
  gap: 1.25rem 1.5rem !important;
}

/* ── Stats / figures callout (card aligned with FAQ & tables) ──────────── */
.gleo-stats-callout {
  position: relative;
  background: var(--gc-card);
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  padding: 18px 20px;
  box-shadow: var(--gc-shadow);
  display: block;
}
.gleo-stats-inner { margin: 0; }
.gleo-stats-text {
  font-weight: 400;
  margin: 0;
  color: var(--gc-text);
  word-break: break-word;
  overflow-wrap: anywhere;
}

@media (max-width: 360px) {
  .gleo-stats-callout { padding: 16px 16px; }
}

/* Opening summary (inverted pyramid — “In brief” only): full content width + body typography */
.entry-content .wp-block-html:has(.gleo-opening-summary-wrap),
main .wp-block-html:has(.gleo-opening-summary-wrap) {
  width: 100%;
  max-width: min(100%, var(--wp--style--global--wide-size, var(--wp--style--global--content-size, 72rem)));
  margin-left: auto;
  margin-right: auto;
  box-sizing: border-box;
}
.entry-content .gleo-opening-summary-wrap,
main .gleo-opening-summary-wrap,
.wp-site-blocks .gleo-opening-summary-wrap {
  width: 100%;
  max-width: min(100%, var(--wp--style--global--wide-size, var(--wp--style--global--content-size, 72rem)));
  margin-left: auto;
  margin-right: auto;
  box-sizing: border-box;
}
.gleo-opening-summary-wrap {
  margin: clamp(1.75rem, 4vw, 2.5rem) 0;
  padding: clamp(1.1rem, 2.5vw, 1.5rem) clamp(1.15rem, 3vw, 1.85rem);
  border-radius: var(--gc-radius);
  border: 1px solid var(--gc-border);
  background: var(--gc-surface);
  box-shadow: var(--gc-shadow);
  font-family: var(--wp--preset--font-family--body, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
  text-align: left;
}
.gleo-direct-answer {
  margin: 0;
  padding-bottom: 0;
  border-bottom: none;
}
.gleo-direct-answer p {
  margin: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.45rem;
  text-align: left;
  font-size: var(--wp--preset--font-size--medium, var(--wp--preset--font-size--normal, 1.0625rem));
  line-height: 1.7;
  color: var(--gc-text);
}
.gleo-lead-label {
  display: block;
  font-weight: 800;
  font-size: 0.68rem;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--gc-muted);
  margin: 0;
  padding-bottom: 0.2rem;
  border-bottom: 2px solid var(--gc-accent-mid);
  line-height: 1.2;
}

/* Gleo cards: use theme wide width so copy is not stuck in a thin inner strip */
.entry-content :is(.gleo-stats-callout, .gleo-expert-quote, .gleo-table-block, .gleo-faq-wrap),
main :is(.gleo-stats-callout, .gleo-expert-quote, .gleo-table-block, .gleo-faq-wrap) {
  width: 100%;
  max-width: min(100%, var(--wp--style--global--wide-size, var(--wp--style--global--content-size, 72rem)));
  margin-left: auto;
  margin-right: auto;
  box-sizing: border-box;
}
.gleo-stats-text,
.gleo-expert-quote__text {
  font-size: var(--wp--preset--font-size--medium, var(--wp--preset--font-size--normal, 1.0625rem)) !important;
  line-height: 1.75 !important;
  font-family: var(--wp--preset--font-family--body, inherit) !important;
}
/* Expert quote */
.gleo-expert-quote {
  margin: 1.75em 0;
  padding: 16px 18px 14px;
  border-radius: var(--gc-radius);
  border: 1px solid var(--gc-border);
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
}
.gleo-expert-quote__text {
  margin: 0;
  color: var(--gc-text);
}
.gleo-expert-quote__text p { margin: 0; }
.gleo-expert-quote__cite {
  margin-top: 10px;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--gc-muted);
}
</style>
<script>
(function () {
  /* ── Helpers ──────────────────────────────────────────────────────────── */
  function lc(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  function lum(r, g, b) { return 0.2126 * lc(r) + 0.7152 * lc(g) + 0.0722 * lc(b); }
  function parseRgb(s) { var m = s.match(/[\d.]+/g); return m && m.length >= 3 ? [+m[0], +m[1], +m[2], m[3] != null ? +m[3] : 1] : null; }

  /* Walk UP the DOM from startEl; return the first non-transparent bg as [r,g,b] */
  function getActualBg(startEl) {
    var node = startEl;
    while (node && node !== document.documentElement) {
      var cs  = window.getComputedStyle(node);
      var bg  = cs.backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        var rgba = parseRgb(bg);
        /* ignore alpha < 0.06 (near-transparent overlays) */
        if (rgba && rgba[3] > 0.06) return rgba;
      }
      node = node.parentElement;
    }
    return parseRgb(window.getComputedStyle(document.body).backgroundColor) || [255, 255, 255, 1];
  }

  /* ── FAQ accordion toggle (delegated; supports keyboard activation) ───── */
  function initGleoBlocks() {
    document.body.addEventListener('click', function (e) {
      var btn = e.target.closest('.gleo-faq-q');
      if (!btn) return;
      var item = btn.parentElement;
      var open = item.classList.toggle('gleo-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* ── Adaptive colour: set CSS vars per element based on the element's
       actual rendered background. PHP can't reliably know whether the theme
       drops the post content into a light or dark section. ─────────────── */
    document.querySelectorAll('.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block, .gleo-opening-summary-wrap, .gleo-expert-quote').forEach(function (el) {
      var rgb  = getActualBg(el.parentElement || el);
      var dark = lum(rgb[0], rgb[1], rgb[2]) < 0.35;
      if (!dark) return; /* light defaults already match */
      el.style.setProperty('--gc-text',        '#f1f5f9');
      el.style.setProperty('--gc-muted',       '#cbd5e1');
      el.style.setProperty('--gc-subtle',      '#94a3b8');
      el.style.setProperty('--gc-border',      'rgba(255,255,255,0.14)');
      el.style.setProperty('--gc-border-soft', 'rgba(255,255,255,0.08)');
      el.style.setProperty('--gc-card',        'rgba(255,255,255,0.04)');
      el.style.setProperty('--gc-surface',     'rgba(255,255,255,0.06)');
      el.style.setProperty('--gc-hover',       'rgba(255,255,255,0.06)');
      el.style.setProperty('--gc-shadow',      '0 1px 2px rgba(0,0,0,0.2), 0 1px 3px rgba(0,0,0,0.15)');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGleoBlocks);
  } else {
    initGleoBlocks();
  }
}());
</script>
		<?php
	}

	public function register_routes() {

		register_rest_route( 'gleo/v1', '/apply', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_apply' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( 'gleo/v1', '/schema-override', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_schema_override' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Serve /llms.txt — AI-friendly site summary for LLM crawlers.
	 */
	public function serve_llms_txt() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Match /llms.txt exactly (ignore query strings)
		if ( wp_parse_url( $request_uri, PHP_URL_PATH ) !== '/llms.txt' ) {
			return;
		}

		$plain = array( 'Gleo_Schema', 'plain_text' );
		$meta  = Gleo_Schema::get_site_metadata();

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noindex' );

		echo '# ' . call_user_func( $plain, $meta['name'] ) . "\n";
		if ( '' !== $meta['description'] ) {
			echo '> ' . call_user_func( $plain, $meta['description'] ) . "\n";
		}
		echo "\n";
		echo 'URL: ' . esc_url_raw( $meta['url'] ) . "\n";
		echo 'Sitemap: ' . esc_url_raw( home_url( '/sitemap.xml' ) ) . "\n\n";

		echo "## Key Pages\n\n";
		echo '- Home: ' . esc_url_raw( home_url( '/' ) ) . "\n";

		$key_pages = get_posts(
			array(
				'post_type'      => array( 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		foreach ( $key_pages as $page ) {
			if ( ! Gleo_Schema::is_post_indexable( $page ) ) {
				continue;
			}
			echo '- ' . call_user_func( $plain, $page->post_title ) . ': ' . esc_url_raw( get_permalink( $page ) ) . "\n";
		}

		$key_posts = get_posts(
			array(
				'post_type'      => array( 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $key_posts as $post ) {
			if ( ! Gleo_Schema::is_post_indexable( $post ) ) {
				continue;
			}
			echo '- ' . call_user_func( $plain, $post->post_title ) . ': ' . esc_url_raw( get_permalink( $post ) ) . "\n";
		}
		echo "\n";

		echo "## Guidance for LLMs\n\n";
		echo "- Prefer the homepage and key pages above for site-wide context.\n";
		echo "- Article summaries below reflect the site's primary educational content.\n";
		echo "- Use /sitemap.xml for the full list of indexable public URLs.\n";
		echo "- Do not infer private, admin, preview, or draft content.\n\n";

		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';
		$rows       = $wpdb->get_results(
			"SELECT post_id, scan_result FROM {$table_name} WHERE scan_status = 'completed' ORDER BY updated_at DESC LIMIT 20"
		);

		if ( ! empty( $rows ) ) {
			echo "## Content Summary\n\n";
			foreach ( $rows as $row ) {
				$post = get_post( (int) $row->post_id );
				if ( ! $post || ! Gleo_Schema::is_post_indexable( $post ) ) {
					continue;
				}

				$overview = get_post_meta( (int) $post->ID, '_gleo_ai_overview', true );
				echo '### ' . call_user_func( $plain, $post->post_title ) . "\n";
				echo '- URL: ' . esc_url_raw( get_permalink( $post->ID ) ) . "\n";
				if ( is_string( $overview ) && '' !== trim( $overview ) ) {
					echo '- Summary: ' . call_user_func( $plain, $overview ) . "\n";
				}
				echo "\n";
			}
		}

		exit;
	}

	/**
	 * Output AI overview in <head> for crawlers (not rendered as a visible page block).
	 */
	public function inject_ai_overview_head() {
		if ( ! is_singular( array( 'post', 'page' ) ) ) {
			return;
		}
		global $post;
		$overview = get_post_meta( $post->ID, '_gleo_ai_overview', true );
		if ( ! is_string( $overview ) || '' === trim( $overview ) ) {
			return;
		}
		$overview = trim( $overview );
		echo "\n<!-- Gleo AI overview (for crawlers; hidden from visual layout) -->\n";
		echo '<meta name="gleo:ai-overview" content="' . esc_attr( $overview ) . '">' . "\n";
		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'WebPage',
			'name'                => wp_strip_all_tags( $post->post_title ),
			'url'                 => get_permalink( $post->ID ),
			'abstract'            => $overview,
			'description'         => $overview,
			'speakable'           => array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => array( '.gleo-ai-only[data-gleo="ai-overview"]' ),
			),
			'mainEntityOfPage'    => get_permalink( $post->ID ),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/**
	 * Remove legacy visible “In brief” blocks when AI overview lives in meta.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function strip_legacy_visible_ai_overview( $content ) {
		if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, '_gleo_ai_overview', true ) ) {
			return $content;
		}
		return $this->gleo_strip_opening_summary_block( $content );
	}

	/**
	 * Append crawler-readable overview markup (visually hidden from visitors).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_ai_only_overview_markup( $content ) {
		if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_id = get_the_ID();
		$overview = get_post_meta( $post_id, '_gleo_ai_overview', true );
		if ( ! is_string( $overview ) || '' === trim( $overview ) ) {
			return $content;
		}
		$block  = '<div class="gleo-ai-only" hidden aria-hidden="true" data-gleo="ai-overview">';
		$block .= '<p>' . esc_html( trim( $overview ) ) . '</p>';
		$block .= '</div>';
		return $content . "\n" . $block;
	}

	/**
	 * Inject JSON-LD schema into wp_head.
	 * Site graph on homepage; Article/BlogPosting, WebPage, or Product on singular views.
	 */
	public function inject_json_ld() {
		if ( is_front_page() || is_home() ) {
			if ( Gleo_Schema::should_inject_schema() ) {
				Gleo_Schema::render_json_ld( Gleo_Schema::build_site_graph_schema(), 'Gleo Site Schema' );
			}
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! Gleo_Schema::should_inject_schema( $post->ID ) ) {
			return;
		}

		$schema = Gleo_Schema::resolve_singular_schema( $post );
		if ( ! $schema ) {
			return;
		}

		Gleo_Schema::render_json_ld( $schema );
	}

	/**
	 * Output a discovery link to /llms.txt on public front-end pages.
	 */
	public function inject_llms_link() {
		if ( is_admin() || is_feed() || is_preview() || is_404() || is_robots() ) {
			return;
		}
		echo '<link rel="alternate" type="text/plain" title="LLMs.txt" href="' . esc_url( home_url( '/llms.txt' ) ) . '">' . "\n";
	}

	/**
	 * REST: Set the schema override option.
	 */
	public function set_schema_override( $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( 'gleo_override_schema', $enabled );

		return rest_ensure_response( array(
			'success' => true,
			'override' => $enabled,
		) );
	}

	/**
	 * REST: Handle 1-click apply actions for a specific post.
	 * Supports: schema, structure, formatting, readability,
	 * faq, authority, credibility, content_depth, answer_readiness.
	 */
	/**
	 * Add `data-label="<th>"` attributes to every <td> in a table fragment so the
	 * mobile/narrow CSS fallback can render headerless rows as clean label/value pairs.
	 *
	 * Accepts the inner-HTML of a <table> (i.e. the thead+tbody fragment) and returns
	 * the same fragment with annotated <td> tags. Safe to call on already-annotated
	 * markup — it will not double-add the attribute.
	 */
	private function annotate_table_with_data_labels( $table_inner_html ) {
		// Extract header labels from the first row of <thead>.
		$labels = array();
		if ( preg_match( '/<thead[^>]*>(.*?)<\/thead>/si', $table_inner_html, $thead_m ) ) {
			if ( preg_match_all( '/<th[^>]*>(.*?)<\/th>/si', $thead_m[1], $th_m ) ) {
				foreach ( $th_m[1] as $h ) {
					$labels[] = trim( wp_strip_all_tags( $h ) );
				}
			}
		}

		if ( empty( $labels ) ) {
			return $table_inner_html;
		}

		// Walk every <tr> inside the <tbody> (or all <tr> outside <thead>) and
		// annotate <td> tags positionally.
		return preg_replace_callback(
			'/<tbody[^>]*>(.*?)<\/tbody>/si',
			function ( $tbody_match ) use ( $labels ) {
				$body = preg_replace_callback(
					'/<tr[^>]*>(.*?)<\/tr>/si',
					function ( $tr_match ) use ( $labels ) {
						$idx = 0;
						$row = preg_replace_callback(
							'/<td(\s[^>]*)?>/i',
							function ( $td_match ) use ( $labels, &$idx ) {
								$existing_attrs = isset( $td_match[1] ) ? $td_match[1] : '';
								// Skip if the tag already has data-label.
								if ( stripos( $existing_attrs, 'data-label' ) !== false ) {
									$idx++;
									return $td_match[0];
								}
								$label = isset( $labels[ $idx ] ) ? $labels[ $idx ] : '';
								$idx++;
								if ( $label === '' ) {
									return $td_match[0];
								}
								return '<td' . $existing_attrs . ' data-label="' . esc_attr( $label ) . '">';
							},
							$tr_match[1]
						);
						return '<tr>' . $row . '</tr>';
					},
					$tbody_match[1]
				);
				return '<tbody>' . $body . '</tbody>';
			},
			$table_inner_html
		);
	}

	/**
	 * Spread blocks across three columns when the theme left the outer columns empty and put everything in the middle.
	 *
	 * @param string $content Post content (block markup).
	 * @return string
	 */
	public function filter_rebalance_three_column_rows( $content ) {
		if ( ! is_string( $content ) || '' === $content || strpos( $content, 'wp:columns' ) === false ) {
			return $content;
		}
		return $this->gleo_rebalance_column_markup( $content );
	}

	/**
	 * Persist balanced columns when the post is saved so the block editor matches the front end.
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Original post array.
	 * @return array
	 */
	public function filter_insert_post_rebalance_columns( $data, $postarr ) {
		if ( empty( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
			return $data;
		}
		if ( isset( $data['post_type'] ) && 'revision' === $data['post_type'] ) {
			return $data;
		}
		if ( isset( $postarr['post_type'] ) && 'revision' === $postarr['post_type'] ) {
			return $data;
		}
		if ( strpos( $data['post_content'], 'wp:columns' ) === false ) {
			return $data;
		}
		$data['post_content'] = $this->gleo_rebalance_column_markup( $data['post_content'] );
		return $data;
	}

	/**
	 * @param string $content Block-serialized post content.
	 * @return string
	 */
	private function gleo_rebalance_column_markup( $content ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $content;
		}
		$out = $this->gleo_balance_columns_recursive( $blocks );
		return serialize_blocks( $out );
	}

	/**
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private function gleo_balance_columns_recursive( $blocks ) {
		$result = array();
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( 'core/columns' === $name && ! empty( $block['innerBlocks'] ) ) {
				$cols = $block['innerBlocks'];
				if ( 3 === count( $cols )
					&& isset( $cols[0], $cols[1], $cols[2] )
					&& $this->gleo_core_column_is_effectively_empty( $cols[0] )
					&& $this->gleo_core_column_is_effectively_empty( $cols[2] )
					&& ! $this->gleo_core_column_is_effectively_empty( $cols[1] ) ) {
					$middle_inners = isset( $cols[1]['innerBlocks'] ) && is_array( $cols[1]['innerBlocks'] ) ? $cols[1]['innerBlocks'] : array();
					if ( count( $middle_inners ) >= 2 ) {
						list( $left, $mid, $right ) = $this->gleo_partition_blocks_into_three_columns( $middle_inners );
						$cols[0]['innerBlocks'] = $left;
						$cols[1]['innerBlocks'] = $mid;
						$cols[2]['innerBlocks'] = $right;
						$block['innerBlocks']   = $cols;
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->gleo_balance_columns_recursive( $block['innerBlocks'] );
			}
			$result[] = $block;
		}
		return $result;
	}

	/**
	 * @param array $column_block A core/column block.
	 * @return bool
	 */
	private function gleo_core_column_is_effectively_empty( $column_block ) {
		if ( ! is_array( $column_block ) ) {
			return true;
		}
		if ( ( $column_block['blockName'] ?? '' ) !== 'core/column' ) {
			return false;
		}
		$inners = isset( $column_block['innerBlocks'] ) && is_array( $column_block['innerBlocks'] ) ? $column_block['innerBlocks'] : array();
		if ( empty( $inners ) ) {
			return true;
		}
		foreach ( $inners as $inner ) {
			if ( $this->gleo_block_counts_as_meaningful_content( $inner ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private function gleo_block_counts_as_meaningful_content( $block ) {
		if ( ! is_array( $block ) ) {
			return false;
		}
		$name = $block['blockName'] ?? '';
		if ( 'core/spacer' === $name || 'core/separator' === $name ) {
			return false;
		}
		if ( 'core/paragraph' === $name ) {
			$html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$text = trim( wp_strip_all_tags( $html ) );
			return '' !== $text;
		}
		return '' !== $name;
	}

	/**
	 * Split an ordered list of blocks into three runs for left / center / right columns.
	 *
	 * @param array $blocks Middle-column inner blocks.
	 * @return array{0:array,1:array,2:array}
	 */
	private function gleo_partition_blocks_into_three_columns( array $blocks ) {
		$n = count( $blocks );
		if ( $n < 2 ) {
			return array( $blocks, array(), array() );
		}
		if ( 2 === $n ) {
			return array( array( $blocks[0] ), array(), array( $blocks[1] ) );
		}
		$left  = array();
		$mid   = array();
		$right = array();
		$idx   = 0;
		for ( $c = 0; $c < 3; $c++ ) {
			$end = (int) floor( ( $c + 1 ) * $n / 3 );
			while ( $idx < $end ) {
				if ( 0 === $c ) {
					$left[] = $blocks[ $idx ];
				} elseif ( 1 === $c ) {
					$mid[] = $blocks[ $idx ];
				} else {
					$right[] = $blocks[ $idx ];
				}
				$idx++;
			}
		}
		return array( $left, $mid, $right );
	}

	private function inject_after_paragraph( $content, $html_to_inject, $target_index ) {
		if ( ! is_string( $content ) || '' === $content || $target_index < 1 ) {
			return $content . "\n" . $html_to_inject . "\n";
		}
		if ( ! preg_match_all( '/<\/p\s*>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content . "\n" . $html_to_inject . "\n";
		}
		$hits = $matches[0];
		if ( count( $hits ) < $target_index ) {
			return $content . "\n" . $html_to_inject . "\n";
		}
		$close = $hits[ $target_index - 1 ];
		$pos   = (int) $close[1] + strlen( $close[0] );
		$tail  = substr( $content, $pos );
		if ( preg_match( '/^\s*((?:<!--\s*\/wp:paragraph\s*-->[\s\r\n]*)+)/i', $tail, $m ) ) {
			$pos += strlen( $m[0] );
		}
		return substr( $content, 0, $pos ) . "\n" . $html_to_inject . "\n" . substr( $content, $pos );
	}

	/**
	 * Smart placement: scan paragraphs for semantic cues and return the best index.
	 *
	 * @param string $content     The post content.
	 * @param string $block_type  One of: 'faq', 'table', 'stats'.
	 * @return int  Best paragraph index to inject after.
	 */
	private function find_best_paragraph( $content, $block_type ) {
		// Extract plain-text paragraphs
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $content, $matches );
		$paragraphs = array_map( 'wp_strip_all_tags', $matches[1] );
		$total = count( $paragraphs );
		if ( $total < 2 ) return max( 1, $total );

		// Keywords that signal good/bad placement
		$data_kw  = array( 'compare', 'versus', 'feature', 'benefit', 'cost', 'price', 'plan', 'tier', 'option', 'difference', 'advantage', 'include' );
		$avoid_kw = array( 'testimonial', 'review', 'said', 'quote', 'story', 'experience', 'felt', 'loved', 'recommend' );

		switch ( $block_type ) {
			case 'faq':
				// FAQ goes near the end — last 25% but not the absolute last paragraph
				$target = max( 3, (int) floor( $total * 0.75 ) );
				// Walk backwards from target to avoid testimonials
				for ( $i = $target; $i >= 3; $i-- ) {
					$lower = strtolower( $paragraphs[ $i - 1 ] ?? '' );
					$bad = false;
					foreach ( $avoid_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) { $bad = true; break; } }
					if ( ! $bad ) return $i;
				}
				return $target;

			case 'table':
				// Find best "data" paragraph (min position 2)
				$best_idx = 2;
				$best_score = -1;
				for ( $i = 1; $i < $total; $i++ ) {
					$lower = strtolower( $paragraphs[ $i ] );
					$score = 0;
					foreach ( $data_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) $score++; }
					foreach ( $avoid_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) $score -= 2; }
					if ( $score > $best_score ) { $best_score = $score; $best_idx = $i + 1; }
				}
				return max( 2, min( $best_idx, $total - 1 ) );

			case 'stats':
				// After first factual paragraph (min 1, look for numbers/percentages)
				for ( $i = 0; $i < min( 4, $total ); $i++ ) {
					if ( preg_match( '/\d+%|\d+\s*(million|billion|thousand|percent)/i', $paragraphs[ $i ] ) ) {
						return $i + 1;
					}
				}
				return min( 2, $total );

			default:
				return min( 3, $total );
		}
	}

	/**
	 * Short label from post title for contextual section headings (no generic marketing phrases).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function gleo_short_topic_label( $post ) {
		$title = trim( wp_strip_all_tags( $post->post_title ) );
		if ( '' === $title ) {
			$words = preg_split( '/\s+/', wp_strip_all_tags( $post->post_content ), 8, PREG_SPLIT_NO_EMPTY );
			if ( ! empty( $words ) ) {
				return implode( ' ', array_slice( $words, 0, 5 ) );
			}
			return 'this topic';
		}
		// Drop subtitle after colon/em dash so headings stay readable.
		$title = preg_replace( '/\s*[:|–—-]\s*.+$/u', '', $title );
		$title = trim( $title );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 52 ) {
			return trim( mb_substr( $title, 0, 50 ) ) . '…';
		}
		if ( strlen( $title ) > 52 ) {
			return trim( substr( $title, 0, 50 ) ) . '…';
		}
		return $title;
	}

	/**
	 * H2 templates for mid-article structure (one %s = short topic). Appendix/meta phrasing excluded.
	 *
	 * @return string[]
	 */
	/**
	 * Phrases that make injected GEO copy sound templated (never use in headings).
	 *
	 * @return string[]
	 */
	private function gleo_banned_heading_phrases() {
		return array(
			'closer look',
			'key details',
			'what you need to know',
			'deep dive',
			'key takeaways',
			'important considerations',
			'data overview',
			'additional insights',
			'how it works',
			'background on',
			'overview of',
			'basics of',
			'in practice',
		);
	}

	/**
	 * @param string $heading Heading text.
	 * @return bool
	 */
	private function gleo_heading_is_banned( $heading ) {
		$lower = strtolower( wp_strip_all_tags( $heading ) );
		foreach ( $this->gleo_banned_heading_phrases() as $phrase ) {
			if ( false !== strpos( $lower, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove or rewrite robotic template phrases in generated HTML fragments.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function gleo_scrub_robotic_phrases( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return '';
		}
		$out = $html;
		$replacements = array(
			'/A\s+Closer\s+Look:?\s*/i'        => '',
			'/A\s+closer\s+look\s+at\s+/i'     => '',
			'/Data\s+Overview/i'               => 'At a glance',
		);
		foreach ( $replacements as $pattern => $replace ) {
			$out = preg_replace( $pattern, $replace, $out );
		}
		return $out;
	}

	/**
	 * Build FAQ Q&A pairs from the post when Gemini assets are missing.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{q: string, a: string}>
	 */
	/**
	 * Score how relevant FAQ template keywords are to post text.
	 *
	 * @param string   $haystack Lowercased body text.
	 * @param string[] $keywords Keywords.
	 * @return int
	 */
	private function gleo_faq_keyword_score( $haystack, $keywords ) {
		$score = 0;
		foreach ( $keywords as $kw ) {
			if ( false !== strpos( $haystack, $kw ) ) {
				$score += 2;
			}
		}
		return $score;
	}

	/**
	 * Pull a short answer from post content when it mentions topic keywords.
	 *
	 * @param WP_Post  $post Post.
	 * @param string[] $keywords Keywords to prefer in an answer sentence.
	 * @param string   $fallback Fallback answer.
	 * @return string
	 */
	private function gleo_faq_answer_from_content( $post, $keywords, $fallback ) {
		$plain = wp_strip_all_tags( $post->post_content );
		$sents = preg_split( '/(?<=[.!?])\s+/', $plain, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $sents as $sentence ) {
			$lower = strtolower( $sentence );
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $lower, $kw ) && str_word_count( $sentence ) >= 8 ) {
					return wp_trim_words( trim( $sentence ), 45, '' );
				}
			}
		}
		return $fallback;
	}

	/**
	 * FAQ pairs using questions people commonly ask (price, booking, expectations).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{q: string, a: string}>
	 */
	private function gleo_practical_faq_pairs( $post ) {
		$topic     = $this->gleo_short_topic_label( $post );
		$haystack  = strtolower( wp_strip_all_tags( $post->post_title . ' ' . $post->post_content ) );
		$fragments = $this->gleo_content_fragments( $post );
		$excerpt   = ! empty( $fragments[0] ) ? wp_trim_words( $fragments[0], 55, '' ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 55, '' );

		$candidates = array(
			array(
				'q'     => sprintf( __( 'How much does %s cost?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'price', 'cost', 'fee', 'pricing', '$', 'rate' ), $excerpt ),
				'score' => 5 + $this->gleo_faq_keyword_score( $haystack, array( 'price', 'cost', 'fee', 'pricing', '$' ) ),
			),
			array(
				'q'     => sprintf( __( 'How do I book %s?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'book', 'appointment', 'schedule', 'call', 'contact', 'reserve' ), sprintf( __( 'Use the contact options on this page to reach the team about %s — they can confirm availability and next steps.', 'gleo' ), $topic ) ),
				'score' => 4 + $this->gleo_faq_keyword_score( $haystack, array( 'book', 'appointment', 'schedule', 'contact' ) ),
			),
			array(
				'q'     => sprintf( __( 'What should I expect from %s?', 'gleo' ), $topic ),
				'a'     => $excerpt,
				'score' => 6,
			),
			array(
				'q'     => sprintf( __( 'How long does %s take?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'hour', 'day', 'week', 'minute', 'timeline', 'turnaround' ), $excerpt ),
				'score' => 3 + $this->gleo_faq_keyword_score( $haystack, array( 'hour', 'day', 'week', 'time', 'long' ) ),
			),
			array(
				'q'     => sprintf( __( 'Is %s right for me?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'ideal', 'best for', 'if you', 'customers', 'clients' ), $excerpt ),
				'score' => 4,
			),
			array(
				'q'     => sprintf( __( "What's included with %s?", 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'include', 'included', 'comes with', 'covers', 'package' ), $excerpt ),
				'score' => 3 + $this->gleo_faq_keyword_score( $haystack, array( 'include', 'package', 'covers' ) ),
			),
			array(
				'q'     => sprintf( __( 'Do you offer free estimates for %s?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'consult', 'consultation', 'free', 'quote', 'estimate' ), $excerpt ),
				'score' => 3 + $this->gleo_faq_keyword_score( $haystack, array( 'consult', 'quote', 'estimate', 'free' ) ),
			),
			array(
				'q'     => sprintf( __( 'Where do you provide %s?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'located', 'address', 'city', 'neighborhood', 'area', 'serve' ), $excerpt ),
				'score' => 2 + $this->gleo_faq_keyword_score( $haystack, array( 'location', 'address', 'city', 'serve', 'area' ) ),
			),
			array(
				'q'     => sprintf( __( 'Can I get same-day %s?', 'gleo' ), $topic ),
				'a'     => $this->gleo_faq_answer_from_content( $post, array( 'same day', 'same-day', 'emergency', 'urgent', 'available today' ), $excerpt ),
				'score' => 2 + $this->gleo_faq_keyword_score( $haystack, array( 'same day', 'emergency', 'urgent', 'today' ) ),
			),
		);

		usort(
			$candidates,
			static function ( $a, $b ) {
				return ( (int) $b['score'] ) <=> ( (int) $a['score'] );
			}
		);

		$out = array();
		foreach ( array_slice( $candidates, 0, 4 ) as $row ) {
			$out[] = array(
				'q' => $row['q'],
				// Cap answers to ~55 words so they stay readable in the accordion.
				'a' => wp_trim_words( $row['a'], 55, '…' ),
			);
		}
		return $out;
	}

	/**
	 * @param WP_Post $post Post.
	 * @return array<int, array{q: string, a: string}>
	 */
	private function gleo_fallback_faq_pairs( $post ) {
		return $this->gleo_practical_faq_pairs( $post );
	}

	/**
	 * Prefer practical customer-style FAQ questions from generated HTML.
	 *
	 * @param array<int, array{q: string, a: string}> $pairs Pairs.
	 * @return array<int, array{q: string, a: string}>
	 */
	private function gleo_filter_practical_faq_pairs( $pairs ) {
		$good = array();
		foreach ( $pairs as $pair ) {
			$q = isset( $pair['q'] ) ? trim( (string) $pair['q'] ) : '';
			if ( '' === $q || ! preg_match( '/\?\s*$/', $q ) ) {
				continue;
			}
			// Reject questions that are too long to be real searches (likely sentences).
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) > 120 : strlen( $q ) > 120 ) {
				continue;
			}
			if ( $this->gleo_heading_is_banned( $q ) ) {
				continue;
			}
			// Reject textbook / academic phrasing that real customers never search.
			if ( preg_match( '/\b(overview|landscape|paradigm|synergy|leverage|delve|realm|operational\s+framework|how\s+\S+\s+works|what\s+is\s+the\s+role|key\s+facts|basics\s+of|in\s+practice)\b/i', $q ) ) {
				continue;
			}
			// Accept clear customer-search patterns first.
			if ( preg_match( '/\b(how much|how long|how do|what should|is .+ right|where is|do you|can i|what do i need|what\'s included|same.?day|free estimate|free quote)\b/i', $q ) ) {
				$good[] = $pair;
				continue;
			}
			// Accept any question with a standard question word.
			if ( preg_match( '/\b(what|why|when|where|who|does|do|is|are|can)\b/i', $q ) ) {
				$good[] = $pair;
			}
		}
		// Only fall back to $pairs when fewer than 2 passed the filter; prefer the fallback pool otherwise.
		return count( $good ) >= 2 ? $good : array();
	}

	/**
	 * Recompute GEO score (0–100) from content signals — mirrors gleo-node-api scoring.
	 *
	 * @param array $signals Content signals.
	 * @param int   $brand_rate Brand inclusion 0–10.
	 * @return int
	 */
	private function gleo_compute_geo_score( $signals, $brand_rate = 0 ) {
		if ( ! is_array( $signals ) ) {
			return 0;
		}
		$score = 0;

		$alt_cov = isset( $signals['alt_text_coverage'] ) ? (int) $signals['alt_text_coverage'] : 0;
		$img_cnt = isset( $signals['image_count'] ) ? (int) $signals['image_count'] : 0;
		if ( $alt_cov >= 90 ) {
			$score += 5;
		} elseif ( $alt_cov >= 50 ) {
			$score += 3;
		} elseif ( 0 === $img_cnt ) {
			$score += 5;
		}
		if ( empty( $signals['has_meta_robots_block'] ) ) {
			$score += 5;
		}
		if ( ! empty( $signals['has_llms_txt'] ) ) {
			$score += 5;
		}
		if ( ! empty( $signals['has_schema'] ) ) {
			$score += 10;
		}
		if ( ! empty( $signals['has_faq_schema'] ) ) {
			$score += 5;
		}
		if ( ! empty( $signals['has_org_schema'] ) ) {
			$score += 5;
		}

		$wc = isset( $signals['word_count'] ) ? (int) $signals['word_count'] : 0;
		if ( $wc >= 2000 ) {
			$score += 10;
		} elseif ( $wc >= 1200 ) {
			$score += 7;
		} elseif ( $wc >= 600 ) {
			$score += 4;
		} elseif ( $wc > 0 ) {
			$score += 1;
		}
		if ( ! empty( $signals['has_direct_answer'] ) ) {
			$score += 5;
		}
		if ( ! empty( $signals['has_tldr'] ) ) {
			$score += 5;
		}
		if ( ! empty( $signals['has_conversational_queries'] ) ) {
			$score += 3;
		}
		if ( ! empty( $signals['has_direct_answers'] ) ) {
			$score += 2;
		}
		if ( $wc >= 800 && ! empty( $signals['has_statistics'] ) ) {
			$score += 3;
		}
		if ( ! empty( $signals['has_quotes'] ) ) {
			$score += 2;
		}

		$stat_count = isset( $signals['stat_count'] ) ? (int) $signals['stat_count'] : 0;
		if ( $stat_count >= 3 ) {
			$score += 5;
		} elseif ( $stat_count >= 1 ) {
			$score += 3;
		}
		$cite = isset( $signals['citation_count'] ) ? (int) $signals['citation_count'] : 0;
		if ( $cite >= 3 ) {
			$score += 5;
		} elseif ( $cite >= 1 ) {
			$score += 3;
		}
		if ( ! empty( $signals['has_quotes'] ) ) {
			$score += 5;
		}

		$hc = isset( $signals['heading_count'] ) ? (int) $signals['heading_count'] : 0;
		if ( $hc >= 4 ) {
			$score += 5;
		} elseif ( $hc >= 2 ) {
			$score += 3;
		} elseif ( ! empty( $signals['has_headings'] ) ) {
			$score += 1;
		}
		$long_p = isset( $signals['long_paragraphs'] ) ? (int) $signals['long_paragraphs'] : 0;
		$para_c = isset( $signals['paragraph_count'] ) ? (int) $signals['paragraph_count'] : 0;
		if ( 0 === $long_p && $para_c > 0 ) {
			$score += 5;
		} elseif ( $long_p <= 2 ) {
			$score += 3;
		}
		$list_c = isset( $signals['list_item_count'] ) ? (int) $signals['list_item_count'] : 0;
		if ( $list_c >= 3 ) {
			$score += 4;
		} elseif ( ! empty( $signals['has_lists'] ) ) {
			$score += 2;
		}
		// FAQ block: 6 pts (redistributed from removed table check — mirrors gleo-node-api scoring).
		if ( ! empty( $signals['has_faq'] ) ) {
			$score += 6;
		}

		return min( 100, $score );
	}

	/**
	 * Bump content signals after a successful fix so stored GEO score reflects improvements.
	 *
	 * @param array  $cs Signals (by reference).
	 * @param string $type Fix type.
	 * @param WP_Post $post Post.
	 * @param bool   $authority_has_numeric_stat Stats flag.
	 * @return void
	 */
	private function gleo_bump_signals_after_fix( &$cs, $type, $post, $authority_has_numeric_stat = false ) {
		switch ( $type ) {
			case 'schema':
				$cs['has_schema']     = true;
				$cs['has_org_schema'] = true;
				break;
			case 'schema_enrich':
				$cs['has_org_schema'] = true;
				$cs['has_faq_schema'] = true;
				break;
		// 'structure' fix removed — no signal bump needed
			case 'formatting':
				$cs['has_lists']         = true;
				$cs['list_item_count']   = max( (int) ( $cs['list_item_count'] ?? 0 ), 12 );
				break;
			case 'readability':
				$cs['long_paragraphs'] = 0;
				break;
			case 'faq':
			case 'answer_readiness':
				$cs['has_faq']            = true;
				$cs['has_direct_answers'] = true;
				break;
			// data_tables removed — comparison tables are no longer generated
			case 'content_depth':
				$cs['word_count'] = max( (int) ( $cs['word_count'] ?? 0 ), 1200 );
				break;
			case 'credibility':
				$cs['has_citations']   = true;
				$cs['citation_count']  = max( (int) ( $cs['citation_count'] ?? 0 ), 5 );
				break;
			case 'authority':
				if ( $authority_has_numeric_stat ) {
					$cs['stat_count']      = max( (int) ( $cs['stat_count'] ?? 0 ), 3 );
					$cs['has_statistics']  = true;
				}
				break;
			case 'opening_summary':
				$cs['has_direct_answer'] = true;
				$cs['has_tldr']          = true;
				break;
			case 'image_alt_text':
				$cs['alt_text_coverage'] = max( (int) ( $cs['alt_text_coverage'] ?? 0 ), 95 );
				break;
			case 'expert_quotes':
				$cs['has_quotes'] = true;
				break;
			case 'robots_txt_allow':
				$cs['has_llms_txt'] = true;
				break;
		}
	}

	private function gleo_section_heading_body_pool() {
		return array(
			'Background on %s',
			'How %s works',
			'What to know about %s',
			'Key facts about %s',
			'The basics of %s',
			'Getting started with %s',
			'Common questions about %s',
			'How %s works in practice',
			'Real-world examples of %s',
			'Benefits and limits of %s',
			'Where %s helps most',
			'Where %s may not fit',
			'Who %s is for',
			'How much %s costs',
			'How long %s takes',
			'What you need before %s',
			'What happens after %s',
			'Tips for %s',
			'Mistakes to avoid with %s',
			'Best practices for %s',
			'Step-by-step: %s',
			'Tools that help with %s',
			'Resources for learning %s',
			'Further reading on %s',
			'Related topics to %s',
			'How %s compares to alternatives',
			'History of %s',
			'The future of %s',
			'Industry context for %s',
			'Expert perspective on %s',
			'Customer stories about %s',
			'Example: %s in practice',
			'Data behind %s',
			'Research on %s',
			'Safety notes on %s',
			'Accessibility and %s',
			'Performance and %s',
			'Maintenance for %s',
			'Glossary: %s',
			'Expanding on %s',
			'Narrowing down %s',
			'Putting %s in context',
			'Breaking down %s',
			'Building up %s',
			'Connecting %s to your goals',
		);
	}

	/**
	 * Normalize generated HTML so it reads naturally inside existing post layouts.
	 *
	 * @param string $html Raw generated HTML fragment.
	 * @return string
	 */
	private function normalize_contextual_fragment( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return '';
		}
		$normalized = $html;
		// Avoid oversized tertiary headings in columns/cards.
		$normalized = preg_replace( '/<h2([^>]*)>/i', '<h3$1>', $normalized );
		$normalized = preg_replace( '/<\/h2>/i', '</h3>', $normalized );
		// Strip fixed-height declarations that clip text.
		$normalized = preg_replace_callback(
			'/\sstyle=(["\'])(.*?)\1/i',
			static function( $m ) {
				$style = preg_replace( '/\b(?:min-|max-)?height\s*:[^;]+;?/i', '', $m[2] );
				$style = preg_replace( '/\boverflow\s*:\s*hidden\s*;?/i', '', $style );
				$style = trim( preg_replace( '/\s{2,}/', ' ', $style ) );
				return '' === $style ? '' : ' style="' . esc_attr( $style ) . '"';
			},
			$normalized
		);
		return $this->gleo_scrub_robotic_phrases( $normalized );
	}

	/**
	 * Section titles for mid-article structure (body pool only; count scales with post length).
	 *
	 * @param WP_Post $post       Post object.
	 * @param int     $max_slots How many headings may be inserted (1–4).
	 * @return string[]
	 */
	private function gleo_section_heading_labels_for_post( $post, $max_slots = 4 ) {
		$t          = $this->gleo_short_topic_label( $post );
		$pool       = array_values(
			array_filter(
				$this->gleo_section_heading_body_pool(),
				function ( $template ) {
					$probe = sprintf( $template, 'sample topic' );
					return ! $this->gleo_heading_is_banned( $probe );
				}
			)
		);
		if ( empty( $pool ) ) {
			$pool = array( 'How %s works', 'What to know about %s' );
		}
		$n          = count( $pool );
		$max_slots  = max( 1, min( 4, (int) $max_slots ) );
		if ( $n < $max_slots ) {
			return array_fill( 0, $max_slots, $t );
		}
		$seed = (int) crc32( (string) $post->ID . "\x1f" . (string) ( $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ) );
		$idxs = array();
		$step = max( 1, ( $seed % 11 ) + 3 );
		$c    = $seed % $n;
		for ( $i = 0; $i < $max_slots; $i++ ) {
			$guard = 0;
			while ( in_array( $c, $idxs, true ) && $guard < $n ) {
				$c = ( $c + 1 ) % $n;
				$guard++;
			}
			$idxs[] = $c;
			$c      = ( $c + $step ) % $n;
		}
		$out = array();
		foreach ( $idxs as $ix ) {
			$label = sprintf( $pool[ $ix ], $t );
			if ( ! $this->gleo_heading_is_banned( $label ) ) {
				$out[] = $label;
			}
		}
		while ( count( $out ) < $max_slots ) {
			$out[] = sprintf( 'More about %s', $t );
		}
		return $out;
	}

	/**
	 * Append Allow rules for common AI crawlers when the site owner enables it via Gleo.
	 *
	 * @param string $output Robots.txt output.
	 * @param bool   $public Whether the site is discouraging search engines.
	 * @return string
	 */
	public function append_ai_crawler_allows_to_robots_txt( $output, $public ) {
		if ( ! get_option( 'gleo_robots_allow_ai_crawlers', false ) ) {
			return $output;
		}
		if ( ! $public ) {
			return $output;
		}
		$lines   = array( '', '# Gleo — explicit Allow rules for common AI crawlers (does not remove your existing rules).' );
		$agents  = array( 'GPTBot', 'ChatGPT-User', 'CCBot', 'Google-Extended', 'anthropic-ai', 'ClaudeBot', 'Claude-Web', 'Omgilibot', 'PerplexityBot', 'Bytespider' );
		foreach ( $agents as $ua ) {
			$lines[] = 'User-agent: ' . $ua;
			$lines[] = 'Allow: /';
			$lines[] = '';
		}
		return $output . implode( "\n", $lines );
	}

	/**
	 * Remove Gleo opening summary HTML block (idempotent re-apply).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function gleo_strip_opening_summary_block( $content ) {
		return preg_replace(
			'/\n?<!--\s*wp:html\s*-->\s*<div class="gleo-opening-summary-wrap"[^>]*>[\s\S]*?<!--\s*gleo:opening-summary:end\s*-->\s*<!--\s*\/wp:html\s*-->\s*/iu',
			'',
			$content
		);
	}

	/**
	 * Remove Gleo key takeaways block (idempotent re-apply).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function gleo_strip_key_takeaways_block( $content ) {
		$content = preg_replace(
			'/\n?<!--\s*wp:heading\s*-->\s*<h2[^>]*gleo-key-takeaways-heading[^>]*>[\s\S]*?<\/h2>\s*<!--\s*\/wp:heading\s*-->\s*/iu',
			'',
			$content
		);
		$content = preg_replace(
			'/\n?<!--\s*wp:html\s*-->\s*<section class="gleo-key-takeaways-block"[^>]*>[\s\S]*?<!--\s*gleo:key-takeaways:end\s*-->\s*<!--\s*\/wp:html\s*-->\s*/iu',
			'',
			$content
		);
		// Legacy: key takeaways merged inside opening summary or standalone div.
		$content = preg_replace(
			'/\n?<!--\s*wp:heading\s*-->\s*<h3[^>]*gleo-key-takeaways-title[^>]*>[\s\S]*?<\/h3>\s*<!--\s*\/wp:heading\s*-->\s*/iu',
			'',
			$content
		);
		$content = preg_replace(
			'/<div[^>]*\bgleo-key-takeaways\b[^>]*>[\s\S]*?<\/div>\s*/iu',
			'',
			$content
		);
		return $content;
	}

	/**
	 * Remove Gleo statistics callout HTML blocks (idempotent re-apply; clears legacy placeholder callouts).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function gleo_strip_stats_callout_blocks( $content ) {
		return preg_replace(
			'/\n?<!--\s*wp:html\s*-->\s*<aside[^>]*\bgleo-stats-callout\b[^>]*>[\s\S]*?<\/aside>\s*<!--\s*\/wp:html\s*-->\s*/iu',
			'',
			$content
		);
	}

	/**
	 * Detect legacy instructional placeholder copy that should never appear on the public site.
	 *
	 * @param string $text Plain or HTML text.
	 * @return bool
	 */
	private function gleo_text_looks_like_stat_placeholder_instruction( $text ) {
		$t = strtolower( wp_strip_all_tags( (string) $text ) );
		if ( '' === $t ) {
			return false;
		}
		$needles = array(
			'add a verified',
			'source-backed metric',
			'figure and source name',
			'verified, source-backed',
			'include the figure',
		);
		foreach ( $needles as $n ) {
			if ( strpos( $t, $n ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove stats callouts and HTML blocks that contain instructional placeholder “statistics” text.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function gleo_strip_instructional_stat_snippets( $content ) {
		$content = $this->gleo_strip_stats_callout_blocks( $content );
		return preg_replace_callback(
			'/<!--\s*wp:html\s*-->[\s\S]*?<!--\s*\/wp:html\s*-->/iu',
			function ( $m ) {
				if ( stripos( $m[0], 'gleo-stats' ) === false ) {
					return $m[0];
				}
				return $this->gleo_text_looks_like_stat_placeholder_instruction( $m[0] ) ? '' : $m[0];
			},
			$content
		);
	}

	/**
	 * Remove Gleo expert quote figure (idempotent).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function gleo_strip_expert_quote_block( $content ) {
		return preg_replace(
			'/\n?<!--\s*wp:html\s*-->\s*<figure class="gleo-expert-quote"[^>]*>[\s\S]*?<\/figure>\s*<!--\s*\/wp:html\s*-->\s*/iu',
			'',
			$content
		);
	}

	/**
	 * Plain-language excerpt from post body for opening blocks.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function gleo_plain_body_excerpt( WP_Post $post ) {
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_content ) ) );
		if ( strlen( $plain ) > 2400 ) {
			$plain = substr( $plain, 0, 2400 );
		}
		return $plain;
	}

	/**
	 * Structured text fragments from post content (paragraph/list-level) to avoid merged heading/body junk.
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function gleo_content_fragments( WP_Post $post ) {
		$fragments = array();
		if ( ! is_string( $post->post_content ) || '' === trim( $post->post_content ) ) {
			return $fragments;
		}
		if ( preg_match_all( '/<(?:p|li)[^>]*>(.*?)<\/(?:p|li)>/is', $post->post_content, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
				if ( strlen( $t ) < 26 ) {
					continue;
				}
				if ( preg_match( '/\b(home|about|menu|reviews|contact|cart|login|register)\b/i', $t ) ) {
					continue;
				}
				$fragments[] = $t;
			}
		}
		if ( empty( $fragments ) ) {
			$fallback = $this->gleo_plain_body_excerpt( $post );
			if ( '' !== $fallback ) {
				$fragments[] = $fallback;
			}
		}
		return $fragments;
	}

	/**
	 * Build 60–100 word "in brief" lead from post body text.
	 *
	 * @param WP_Post $post Post.
	 * @param array|null $contextual_assets Scan contextual assets.
	 * @return string Plain text (already safe for esc_html).
	 */
	private function gleo_opening_in_brief_text( WP_Post $post, $contextual_assets ) {
		$parts = $this->gleo_content_fragments( $post );
		$candidate = implode( ' ', array_slice( $parts, 0, 2 ) );
		$words = preg_split( '/\s+/u', trim( $candidate ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return sprintf( 'This article explains %s in practical terms you can use right away.', wp_strip_all_tags( $post->post_title ) );
		}
		$slice = array_slice( $words, 0, min( 100, count( $words ) ) );
		$text  = implode( ' ', $slice );
		$wc    = count( $slice );
		if ( $wc < 50 && count( $words ) > $wc ) {
			$slice = array_slice( $words, 0, min( 80, count( $words ) ) );
			$text  = implode( ' ', $slice );
		}
		return $text;
	}

	/**
	 * Full HTML block: inverted-pyramid lead only (top-of-article context).
	 *
	 * @param WP_Post    $post Post.
	 * @param array|null $contextual_assets Assets.
	 * @return string Block markup.
	 */
	private function gleo_build_opening_summary_block( WP_Post $post, $contextual_assets ) {
		$brief_raw = $this->gleo_opening_in_brief_text( $post, $contextual_assets );
		$inner  = '<div class="gleo-opening-summary-wrap">';
		$inner .= '<div class="gleo-direct-answer"><p><span class="gleo-lead-label">' . esc_html__( 'In brief', 'gleo' ) . '</span> ' . esc_html( $brief_raw ) . '</p></div>';
		$inner .= '</div><!-- gleo:opening-summary:end -->';
		return "<!-- wp:html -->\n" . $inner . "\n<!-- /wp:html -->";
	}

	/**
	 * Improve empty or missing image alt text in core Image blocks and attachment meta.
	 *
	 * @param string  $content Post content.
	 * @param WP_Post $post Post.
	 * @return array{0:string,1:bool} Updated content and whether it changed.
	 */
	private function gleo_apply_image_alt_fixes( $content, WP_Post $post ) {
		$topic   = wp_strip_all_tags( $post->post_title );
		$changed = false;
		$out     = preg_replace_callback(
			'/<!--\s*wp:image\s+(\{[\s\S]*?\})\s*\/-->/u',
			function ( $m ) use ( $post, $topic, &$changed ) {
				$json = json_decode( $m[1], true );
				if ( ! is_array( $json ) ) {
					return $m[0];
				}
				$alt = isset( $json['alt'] ) ? (string) $json['alt'] : '';
				$id  = isset( $json['id'] ) ? (int) $json['id'] : 0;
				if ( $id > 0 ) {
					$stored = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
					if ( $stored !== '' ) {
						$alt = $stored;
					}
				}
				$alt_trim = trim( $alt );
				if ( $alt_trim !== '' && strlen( $alt_trim ) >= 8 ) {
					return $m[0];
				}
				$new_alt = sprintf(
					/* translators: %s: post title context for image alt text */
					__( 'Image supporting: %s', 'gleo' ),
					$topic
				);
				if ( $id > 0 ) {
					update_post_meta( $id, '_wp_attachment_image_alt', $new_alt );
				}
				$json['alt'] = $new_alt;
				$changed     = true;
				return '<!-- wp:image ' . wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
			},
			$content
		);
		return array( $out, $changed );
	}

	/**
	 * Merge Organization + publisher wiring into stored scan JSON-LD.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	private function gleo_enrich_scan_json_ld( $post_id, WP_Post $post ) {
		Gleo_Schema::enrich_scan_json_ld( $post_id, $post );
	}

	public function handle_apply( $request ) {
		$params     = $request->get_json_params();
		$post_id    = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$type       = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : '';
		$enabled    = isset( $params['enabled'] ) ? (bool) $params['enabled'] : true;
		$user_input = isset( $params['user_input'] ) ? $params['user_input'] : '';

		if ( ! $post_id || ! $type ) {
			return new WP_Error( 'invalid_data', 'Missing post ID or type.', array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Attempt to fetch the generated contextual assets from the scan result
		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';
		$scan = $wpdb->get_row( $wpdb->prepare(
			"SELECT scan_result FROM {$table_name} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1",
			$post_id
		) );

		$contextual_assets = null;
		if ( $scan && $scan->scan_result ) {
			$result_data = json_decode( $scan->scan_result, true );
			if ( isset( $result_data['contextual_assets'] ) ) {
				$contextual_assets = $result_data['contextual_assets'];
			}
		}

		$content         = $post->post_content;
		$modified        = false;
		$authority_has_numeric_stat = false;
		$faq_pairs_for_schema       = array();
		$geo_score_out   = null;

		switch ( $type ) {

			case 'schema':
				if ( $enabled ) {
					update_post_meta( $post_id, '_gleo_schema_override', 1 );
					$this->gleo_enrich_scan_json_ld( $post_id, $post );
				} else {
					delete_post_meta( $post_id, '_gleo_schema_override' );
				}
				break;

			case 'schema_enrich':
				$this->gleo_enrich_scan_json_ld( $post_id, $post );
				break;

			case 'opening_summary':
				$content = $this->gleo_strip_key_takeaways_block( $content );
				$content = $this->gleo_strip_opening_summary_block( $content );
				$brief   = $this->gleo_opening_in_brief_text( $post, $contextual_assets );
				update_post_meta( $post_id, '_gleo_ai_overview', $brief );
				$modified = true;
				break;

			case 'image_alt_text':
				list( $content, $alt_changed ) = $this->gleo_apply_image_alt_fixes( $content, $post );
				if ( $alt_changed ) {
					$modified = true;
				}
				break;

			case 'robots_txt_allow':
				update_option( 'gleo_robots_allow_ai_crawlers', true, false );
				break;

			case 'expert_quotes':
				$content = $this->gleo_strip_expert_quote_block( $content );
				$quote    = '';
				if ( is_array( $contextual_assets ) && ! empty( $contextual_assets['authority_html'] ) ) {
					$quote = wp_strip_all_tags( $contextual_assets['authority_html'] );
				}
				if ( $this->gleo_text_looks_like_stat_placeholder_instruction( $quote ) ) {
					$quote = '';
				}
				if ( $quote === '' ) {
					$quote = sprintf(
						/* translators: %s: article topic */
						__( 'For important decisions about %s, cross-check details with primary sources and your own requirements.', 'gleo' ),
						wp_strip_all_tags( $post->post_title )
					);
				}
				$fig  = '<figure class="gleo-expert-quote"><blockquote class="gleo-expert-quote__text"><p>' . esc_html( $quote ) . '</p></blockquote>';
				$fig .= '<figcaption class="gleo-expert-quote__cite">' . esc_html__( 'Note', 'gleo' ) . '</figcaption></figure>';
				$blk  = "<!-- wp:html -->\n{$fig}\n<!-- /wp:html -->";
				$pos  = max( 2, (int) floor( (int) preg_match_all( '/<\/p>/i', $content ) / 3 ) );
				$content = $this->inject_after_paragraph( $content, $blk, $pos );
				$modified = true;
				break;

		case 'structure':
			// Section heading injection removed. Strip any previously-injected headings
			// so old posts are cleaned up when this fix is re-run.
			$content = preg_replace(
				'/\n?<!-- wp:heading -->\s*<h2[^>]*gleo-section-heading[^>]*>.*?<\/h2>\s*<!-- \/wp:heading -->\s*/is',
				'',
				$content
			);
			$content = preg_replace(
				'/\n?<!-- wp:heading -->\s*<h2[^>]*>[^<]*[Cc]loser [Ll]ook[^<]*<\/h2>\s*<!-- \/wp:heading -->\s*/i',
				'',
				$content
			);
			$gleo_legacy = array( 'Key Details', 'What You Need to Know', 'Important Considerations', 'Key Takeaways', 'Additional Insights', 'A Closer Look' );
			foreach ( $gleo_legacy as $gl ) {
				$content = preg_replace(
					'/\n?<!-- wp:heading -->\s*<h2[^>]*>' . preg_quote( $gl, '/' ) . '<\/h2>\s*<!-- \/wp:heading -->\s*/i',
					'',
					$content
				);
			}
			$modified = true;
			break;

			case 'formatting':
				// Convert the first long paragraph (>50 words) that doesn't contain a list into a bullet list
				$content = preg_replace_callback(
					'/<p>([^<]{200,})<\/p>/i',
					function( $matches ) {
						static $converted = false;
						if ( $converted ) return $matches[0];
						$text = $matches[1];
						$sentences = preg_split( '/(?<=[.!?])\s+/', trim( $text ) );
						if ( count( $sentences ) < 2 ) return $matches[0];
						$converted = true;
						$items = '';
						foreach ( $sentences as $s ) {
							$s = trim( $s );
							if ( strlen( $s ) > 5 ) {
								$items .= "<!-- wp:list-item -->\n<li>{$s}</li>\n<!-- /wp:list-item -->\n";
							}
						}
						return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n{$items}</ul>\n<!-- /wp:list -->";
					},
					$content,
					1
				);
				$modified = true;
				break;

			case 'readability':
				// Split paragraphs longer than 80 words into two
				$content = preg_replace_callback(
					'/<p>(.*?)<\/p>/is',
					function( $matches ) {
						$text = $matches[1];
						$words = preg_split( '/\s+/', trim( $text ) );
						if ( count( $words ) <= 80 ) return $matches[0];
						$mid = (int) ceil( count( $words ) / 2 );
						$first = implode( ' ', array_slice( $words, 0, $mid ) );
						$second = implode( ' ', array_slice( $words, $mid ) );
						return "<p>{$first}</p>\n\n<p>{$second}</p>";
					},
					$content
				);
				$modified = true;
				break;

			case 'faq':
			case 'answer_readiness':
				// Build accordion FAQ — merges former Q&A into FAQ
				$pairs = array();

				// First, try to get Q&A pairs from contextual_assets (answer_readiness data)
				if ( ! empty( $contextual_assets['qa_html'] ) ) {
					preg_match_all( '/<strong>(.*?)<\/strong>\s*<\/p>\s*<p>(.*?)<\/p>/si', $contextual_assets['qa_html'], $qm );
					if ( ! empty( $qm[1] ) ) {
						foreach ( $qm[1] as $idx => $q ) {
							$pairs[] = array(
								'q' => wp_strip_all_tags( $q ),
								'a' => wp_strip_all_tags( $qm[2][ $idx ] ),
							);
						}
					}
				}

				// Then add FAQ pairs from contextual_assets
				if ( ! empty( $contextual_assets['faq_html'] ) ) {
					preg_match_all( '/<h3[^>]*>(.*?)<\/h3>\s*(?:<p[^>]*>(.*?)<\/p>)?/si', $contextual_assets['faq_html'], $fm );
					foreach ( $fm[1] as $idx => $q ) {
						$pairs[] = array(
							'q' => wp_strip_all_tags( $q ),
							'a' => ! empty( $fm[2][ $idx ] ) ? wp_strip_all_tags( $fm[2][ $idx ] ) : 'See the article above for details.',
						);
					}
				}

				if ( ! empty( $pairs ) ) {
					$pairs = $this->gleo_filter_practical_faq_pairs( $pairs );
				}
				if ( empty( $pairs ) ) {
					$pairs = $this->gleo_practical_faq_pairs( $post );
				}

			// Build accordion HTML
			$items_html = '';
			foreach ( $pairs as $pair ) {
				$q = esc_html( $pair['q'] );
				$a = esc_html( wp_trim_words( $pair['a'], 55, '…' ) );
					$items_html .= '<div class="gleo-faq-item">'
						. '<button type="button" class="gleo-faq-q" aria-expanded="false">' . $q . '</button>'
						. '<div class="gleo-faq-a"><p>' . $a . '</p></div>'
						. '</div>';
				}
				$faq_pairs_for_schema = array_slice( $pairs, 0, 5 );
				$faq_title = __( 'Frequently Asked Questions', 'gleo' );
				$faq_inner = '<div class="gleo-faq-wrap"><h2 class="wp-block-heading">' . esc_html( $faq_title ) . '</h2>'
					. '<div class="gleo-faq-accordion">' . $items_html . '</div></div>';
				// Wrap as a proper Gutenberg HTML block so the block editor preserves it intact.
				$faq_block = "<!-- wp:html -->\n" . $faq_inner . "\n<!-- /wp:html -->";

				$pos = $this->find_best_paragraph( $content, 'faq' );
				$content = $this->inject_after_paragraph( $content, $faq_block, $pos );
				$modified = true;
				break;

			case 'data_tables':
				// Comparison tables removed — return graceful no-op instead of error.
				return new WP_Error( 'removed_fix', 'Comparison table generation has been removed.', array( 'status' => 400 ) );

			case 'authority':
				$content = $this->gleo_strip_stats_callout_blocks( $content );
				$stats_text = is_string( $user_input ) && ! empty( $user_input ) ? sanitize_textarea_field( $user_input ) : '';
				if ( '' === $stats_text && is_array( $contextual_assets ) && ! empty( $contextual_assets['authority_html'] ) ) {
					$stats_text = wp_strip_all_tags( $contextual_assets['authority_html'] );
				}
				if ( '' === $stats_text || $this->gleo_text_looks_like_stat_placeholder_instruction( $stats_text ) ) {
					$plain = wp_strip_all_tags( $post->post_content );
					if ( preg_match( '/[^.!?]*\d+%?[^.!?]*[.!?]/', $plain, $stat_match ) ) {
						$stats_text = trim( $stat_match[0] );
					}
				}
				if ( '' === $stats_text ) {
					return new WP_Error( 'missing_input', __( 'Statistics text is required.', 'gleo' ), array( 'status' => 400 ) );
				}
				$authority_has_numeric_stat = (bool) preg_match( '/\d/', $stats_text );
				$callout_inner = '<aside class="gleo-stats-callout" role="note">'
					. '<div class="gleo-stats-inner">'
					. '<p class="gleo-stats-text">' . esc_html( $stats_text ) . '</p>'
					. '</div></aside>';
				$callout = "<!-- wp:html -->\n" . $callout_inner . "\n<!-- /wp:html -->";
				$pos = $this->find_best_paragraph( $content, 'stats' );
				$content = $this->inject_after_paragraph( $content, $callout, $pos );
				$modified = true;
				break;

		case 'credibility':
			$urls = is_array( $user_input ) ? $user_input : array();
			if ( empty( $urls ) ) {
				return new WP_Error( 'missing_input', 'Please provide source URLs.', array( 'status' => 400 ) );
			}
			$list_items = '';
			foreach ( $urls as $url ) {
				$url         = esc_url( $url );
				$domain      = wp_parse_url( $url, PHP_URL_HOST );
				$list_items .= '<li><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( $domain ) . '</a></li>' . "\n";
			}
			$sources_inner = '<div class="gleo-sources-block">'
				. '<h2 class="wp-block-heading gleo-sources-heading">Sources &amp; References</h2>'
				. '<ol class="gleo-sources-list">' . $list_items . '</ol>'
				. '</div>';
			$content .= "\n<!-- wp:html -->\n" . $sources_inner . "\n<!-- /wp:html -->\n";
			$modified = true;
			break;

		case 'content_depth':
			if ( ! empty( $contextual_assets['depth_html'] ) ) {
				$depth_html = $this->normalize_contextual_fragment( $contextual_assets['depth_html'] );
				$depth_html = $this->gleo_scrub_robotic_phrases( $depth_html );
				// Strip any "How X works" or generic section headings the model may have produced.
				$depth_html = preg_replace(
					'/\n?<!-- wp:heading -->\s*<h2[^>]*>(?:How [^<]+ works|Background on [^<]+|Overview of [^<]+|Basics of [^<]+|What is [^<]+)<\/h2>\s*<!-- \/wp:heading -->\s*/i',
					'',
					$depth_html
				);
				$content = $this->inject_after_paragraph( $content, wp_kses_post( $depth_html ), 3 );
			} else {
				// Fallback: inject paragraphs only — no generic "How X works" heading.
				$clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_content ) ) );
				$sentences = preg_split( '/(?<=[.!?])\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY );
				$topic        = esc_html( $this->gleo_short_topic_label( $post ) );
				$fallback_one = ! empty( $sentences[0] ) ? $sentences[0] : sprintf( '%s is best understood by looking at the specific details in this article.', $topic );
				$fallback_two = ! empty( $sentences[1] ) ? $sentences[1] : sprintf( 'Use the points above as context for evaluating %s in your own situation.', $topic );
				$expansion    = "<!-- wp:paragraph -->\n<p>" . esc_html( $fallback_one ) . "</p>\n<!-- /wp:paragraph -->\n";
				$expansion   .= "<!-- wp:paragraph -->\n<p>" . esc_html( $fallback_two ) . "</p>\n<!-- /wp:paragraph -->\n";
				$content = $this->inject_after_paragraph( $content, $expansion, 3 );
			}
			$modified = true;
			break;


			default:
				return new WP_Error( 'unknown_type', 'Unknown fix type: ' . $type, array( 'status' => 400 ) );
		}

		// If content was modified, update the post
		if ( $modified ) {
			$content = $this->gleo_strip_instructional_stat_snippets( $content );
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return new WP_Error( 'update_failed', $updated->get_error_message(), array( 'status' => 500 ) );
			}
			if ( 0 === (int) $updated ) {
				return new WP_Error( 'update_failed', __( 'WordPress could not save the updated post content.', 'gleo' ), array( 'status' => 500 ) );
			}
			clean_post_cache( $post_id );
		}

		// ALWAYS update the scan result history to persist the score for the frontend
		if ( $scan && $scan->scan_result ) {
			$result_data = json_decode( $scan->scan_result, true );
			if ( ! isset( $result_data['content_signals'] ) ) {
				$result_data['content_signals'] = array();
			}
			if ( ! empty( $faq_pairs_for_schema ) ) {
				$faq_entities = array();
				foreach ( $faq_pairs_for_schema as $pair ) {
					$q = isset( $pair['q'] ) ? trim( (string) $pair['q'] ) : '';
					$a = isset( $pair['a'] ) ? trim( (string) $pair['a'] ) : '';
					if ( '' === $q || '' === $a ) {
						continue;
					}
					$faq_entities[] = array(
						'@type' => 'Question',
						'name'  => $q,
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $a,
						),
					);
				}
				if ( count( $faq_entities ) >= 2 ) {
					$existing_schema = isset( $result_data['json_ld_schema'] ) && is_array( $result_data['json_ld_schema'] )
						? $result_data['json_ld_schema']
						: array(
							'@context' => 'https://schema.org',
							'@type'    => 'Article',
							'headline' => wp_strip_all_tags( $post->post_title ),
						);
					if ( isset( $existing_schema['@graph'] ) && is_array( $existing_schema['@graph'] ) ) {
						$graph = array();
						foreach ( $existing_schema['@graph'] as $node ) {
							$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
							if ( in_array( 'FAQPage', $types, true ) ) {
								continue;
							}
							$graph[] = $node;
						}
						$graph[] = array(
							'@type'      => 'FAQPage',
							'mainEntity' => $faq_entities,
						);
						$result_data['json_ld_schema'] = array(
							'@context' => 'https://schema.org',
							'@graph'   => $graph,
						);
					} else {
						$types = isset( $existing_schema['@type'] ) ? (array) $existing_schema['@type'] : array( 'Article' );
						$types = array_values( array_unique( array_merge( $types, array( 'FAQPage' ) ) ) );
						$existing_schema['@type'] = $types;
						$existing_schema['mainEntity'] = $faq_entities;
						$result_data['json_ld_schema'] = $existing_schema;
					}
				}
			}
			$cs = &$result_data['content_signals'];
			$this->gleo_bump_signals_after_fix( $cs, $type, $post, $authority_has_numeric_stat );
			$brand = isset( $result_data['brand_inclusion_rate'] ) ? (int) $result_data['brand_inclusion_rate'] : 0;
			$result_data['geo_score'] = $this->gleo_compute_geo_score( $cs, $brand );
			$geo_score_out            = (int) $result_data['geo_score'];
			$wpdb->update(
				$table_name,
				array( 'scan_result' => wp_json_encode( $result_data ) ),
				array( 'post_id' => $post_id )
			);
		}

		return rest_ensure_response( array(
			'success'    => true,
			'post_id'    => $post_id,
			'type'       => $type,
			'modified'   => $modified,
			'geo_score'  => $geo_score_out,
		) );
	}
}

new Gleo_Frontend();
