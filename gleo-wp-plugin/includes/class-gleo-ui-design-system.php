<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gleo UI Design System
 *
 * Token-driven CSS generation engine for the UI Optimization feature.
 * Produces genuine visual improvements: typography, layout, navigation,
 * buttons, forms, cards, CTA sections, and mobile — not just color changes.
 */
class Gleo_UI_Design_System {

	const VALID_STYLES = [ 'professional', 'sleek', 'playful', 'bold' ];
	const VALID_THEMES = [ 'green', 'blue', 'purple' ];

	/** Map legacy layout_preset slugs to new visual_style slugs. */
	const PRESET_STYLE_MAP = [
		'clean'     => 'professional',
		'editorial' => 'sleek',
		'bold'      => 'bold',
	];

	// ── Token tables ─────────────────────────────────────────────────────────

	/**
	 * Visual style design tokens (spacing, radii, shadows, fonts, type scale).
	 * These drive layout and structure — color is a separate concern.
	 *
	 * @param string $style 'professional' | 'sleek' | 'playful' | 'bold'
	 * @return array<string, string>
	 */
	public static function get_visual_style_tokens( string $style ): array {
		$styles = [
			'professional' => [
				'radius'              => '8px',
				'radius_sm'           => '4px',
				'radius_lg'           => '14px',
				'shadow_sm'           => '0 1px 3px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04)',
				'shadow_md'           => '0 4px 16px rgba(0,0,0,0.09), 0 2px 4px rgba(0,0,0,0.05)',
				'shadow_lg'           => '0 8px 32px rgba(0,0,0,0.11), 0 4px 8px rgba(0,0,0,0.05)',
				'section_padding'     => '4.5rem',
				'section_padding_sm'  => '2.5rem',
				'content_width'       => '720px',
				'card_padding'        => '1.75rem',
				'btn_padding'         => '0.75rem 1.75rem',
				'btn_radius'          => '8px',
				'font_heading'        => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
				'font_body'           => "'Source Serif 4', 'Cambria', Georgia, 'Times New Roman', serif",
				'font_weight_heading' => '700',
				'font_weight_h1'      => '800',
				'body_size'           => '17px',
				'body_lh'             => '1.78',
				'h1_size'             => 'clamp(1.875rem, 4.5vw, 2.625rem)',
				'h2_size'             => 'clamp(1.375rem, 3vw, 1.875rem)',
				'h3_size'             => 'clamp(1.1rem, 2.5vw, 1.35rem)',
				'h4_size'             => '1.05rem',
				'h1_spacing'          => '-0.03em',
				'h2_spacing'          => '-0.025em',
				'h3_spacing'          => '-0.01em',
				'h2_margin_top'       => '3rem',
				'h3_margin_top'       => '2.25rem',
				'paragraph_gap'       => '1.35rem',
				'input_radius'        => '6px',
				'nav_link_radius'     => '5px',
				'nav_link_padding'    => '0.5rem 0.875rem',
				'card_radius'         => '10px',
				'hero_padding'        => '5.5rem 2rem',
				'cta_padding'         => '4.5rem 2rem',
				'cta_text_align'      => 'center',
				'google_fonts'        => 'Inter:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700',
			],
			'sleek' => [
				'radius'              => '12px',
				'radius_sm'           => '6px',
				'radius_lg'           => '22px',
				'shadow_sm'           => '0 1px 4px rgba(0,0,0,0.05)',
				'shadow_md'           => '0 4px 24px rgba(0,0,0,0.07), 0 1px 4px rgba(0,0,0,0.04)',
				'shadow_lg'           => '0 10px 44px rgba(0,0,0,0.09), 0 3px 8px rgba(0,0,0,0.04)',
				'section_padding'     => '6rem',
				'section_padding_sm'  => '3.5rem',
				'content_width'       => '740px',
				'card_padding'        => '2.25rem',
				'btn_padding'         => '0.9rem 2rem',
				'btn_radius'          => '12px',
				'font_heading'        => "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
				'font_body'           => "'Fraunces', 'Palatino Linotype', 'Book Antiqua', Palatino, serif",
				'font_weight_heading' => '600',
				'font_weight_h1'      => '700',
				'body_size'           => '18px',
				'body_lh'             => '1.85',
				'h1_size'             => 'clamp(2rem, 5.5vw, 3.25rem)',
				'h2_size'             => 'clamp(1.5rem, 3.5vw, 2.125rem)',
				'h3_size'             => 'clamp(1.15rem, 2.5vw, 1.5rem)',
				'h4_size'             => '1.0625rem',
				'h1_spacing'          => '-0.04em',
				'h2_spacing'          => '-0.03em',
				'h3_spacing'          => '-0.015em',
				'h2_margin_top'       => '4rem',
				'h3_margin_top'       => '2.75rem',
				'paragraph_gap'       => '1.5rem',
				'input_radius'        => '10px',
				'nav_link_radius'     => '8px',
				'nav_link_padding'    => '0.5rem 1rem',
				'card_radius'         => '18px',
				'hero_padding'        => '7.5rem 2rem',
				'cta_padding'         => '5.5rem 2rem',
				'cta_text_align'      => 'center',
				'google_fonts'        => 'DM+Sans:wght@300;400;500;600;700&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700',
			],
			'playful' => [
				'radius'              => '16px',
				'radius_sm'           => '8px',
				'radius_lg'           => '28px',
				'shadow_sm'           => '0 2px 8px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04)',
				'shadow_md'           => '0 6px 20px rgba(0,0,0,0.09), 0 2px 6px rgba(0,0,0,0.05)',
				'shadow_lg'           => '0 12px 36px rgba(0,0,0,0.12), 0 4px 10px rgba(0,0,0,0.06)',
				'section_padding'     => '4.5rem',
				'section_padding_sm'  => '2.5rem',
				'content_width'       => '700px',
				'card_padding'        => '1.875rem',
				'btn_padding'         => '0.875rem 2rem',
				'btn_radius'          => '999px',
				'font_heading'        => "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
				'font_body'           => "'Quicksand', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
				'font_weight_heading' => '800',
				'font_weight_h1'      => '900',
				'body_size'           => '17px',
				'body_lh'             => '1.8',
				'h1_size'             => 'clamp(1.875rem, 5vw, 2.875rem)',
				'h2_size'             => 'clamp(1.35rem, 3.5vw, 1.875rem)',
				'h3_size'             => 'clamp(1.1rem, 2.5vw, 1.35rem)',
				'h4_size'             => '1rem',
				'h1_spacing'          => '-0.02em',
				'h2_spacing'          => '-0.01em',
				'h3_spacing'          => '0',
				'h2_margin_top'       => '3rem',
				'h3_margin_top'       => '2rem',
				'paragraph_gap'       => '1.3rem',
				'input_radius'        => '14px',
				'nav_link_radius'     => '999px',
				'nav_link_padding'    => '0.5rem 1.125rem',
				'card_radius'         => '22px',
				'hero_padding'        => '5.5rem 2rem',
				'cta_padding'         => '4.5rem 2rem',
				'cta_text_align'      => 'center',
				'google_fonts'        => 'Nunito:wght@400;600;700;800;900&family=Quicksand:wght@400;500;600;700',
			],
			'bold' => [
				'radius'              => '5px',
				'radius_sm'           => '3px',
				'radius_lg'           => '8px',
				'shadow_sm'           => '0 2px 6px rgba(0,0,0,0.10), 0 1px 2px rgba(0,0,0,0.07)',
				'shadow_md'           => '0 4px 16px rgba(0,0,0,0.14), 0 2px 6px rgba(0,0,0,0.08)',
				'shadow_lg'           => '0 8px 28px rgba(0,0,0,0.18), 0 4px 10px rgba(0,0,0,0.10)',
				'section_padding'     => '4rem',
				'section_padding_sm'  => '2rem',
				'content_width'       => '700px',
				'card_padding'        => '1.5rem',
				'btn_padding'         => '0.875rem 2.25rem',
				'btn_radius'          => '5px',
				'font_heading'        => "'Oswald', -apple-system, BlinkMacSystemFont, 'Arial Narrow', 'Impact', sans-serif",
				'font_body'           => "'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif",
				'font_weight_heading' => '700',
				'font_weight_h1'      => '700',
				'body_size'           => '16px',
				'body_lh'             => '1.72',
				'h1_size'             => 'clamp(2.25rem, 5.5vw, 3.5rem)',
				'h2_size'             => 'clamp(1.5rem, 3.5vw, 2.25rem)',
				'h3_size'             => 'clamp(1.1rem, 2vw, 1.35rem)',
				'h4_size'             => '1rem',
				'h1_spacing'          => '-0.01em',
				'h2_spacing'          => '-0.005em',
				'h3_spacing'          => '0.01em',
				'h2_margin_top'       => '3rem',
				'h3_margin_top'       => '2rem',
				'paragraph_gap'       => '1.25rem',
				'input_radius'        => '4px',
				'nav_link_radius'     => '4px',
				'nav_link_padding'    => '0.5rem 0.875rem',
				'card_radius'         => '5px',
				'hero_padding'        => '5.5rem 2rem',
				'cta_padding'         => '5rem 2rem',
				'cta_text_align'      => 'center',
				'google_fonts'        => 'Oswald:wght@500;600;700&family=Open+Sans:wght@400;500;600;700',
			],
		];
		return $styles[ in_array( $style, self::VALID_STYLES, true ) ? $style : 'professional' ];
	}

	/**
	 * Curated color theme palettes.
	 * Returns full token set for each theme.
	 *
	 * @param string $theme 'green' | 'blue' | 'purple'
	 * @return array<string, string>
	 */
	public static function get_color_theme_palette( string $theme ): array {
		$themes = [
			'green' => [
				'primary'      => '#047857',
				'secondary'    => '#059669',
				'accent'       => '#10b981',
				'cta'          => '#065f46',
				'cta_hover'    => '#064e3b',
				'text'         => '#0f172a',
				'muted'        => '#475569',
				'card'         => '#ffffff',
				'surface'      => '#f0fdf4',
				'border'       => '#bbf7d0',
				'accent_light' => '#a7f3d0',
			],
			'blue' => [
				'primary'      => '#1d4ed8',
				'secondary'    => '#2563eb',
				'accent'       => '#3b82f6',
				'cta'          => '#1e40af',
				'cta_hover'    => '#1e3a8a',
				'text'         => '#0f172a',
				'muted'        => '#475569',
				'card'         => '#ffffff',
				'surface'      => '#eff6ff',
				'border'       => '#bfdbfe',
				'accent_light' => '#93c5fd',
			],
			'purple' => [
				'primary'      => '#6d28d9',
				'secondary'    => '#7c3aed',
				'accent'       => '#8b5cf6',
				'cta'          => '#5b21b6',
				'cta_hover'    => '#4c1d95',
				'text'         => '#0f172a',
				'muted'        => '#475569',
				'card'         => '#ffffff',
				'surface'      => '#f5f3ff',
				'border'       => '#ddd6fe',
				'accent_light' => '#c4b5fd',
			],
		];
		return $themes[ in_array( $theme, self::VALID_THEMES, true ) ? $theme : 'blue' ];
	}

	/**
	 * Build the Google Fonts URL for embedding via wp_enqueue_style.
	 *
	 * @param string $style Visual style slug.
	 * @return string URL or empty string.
	 */
	public static function get_google_fonts_url( string $style ): string {
		$tokens  = self::get_visual_style_tokens( $style );
		$families = $tokens['google_fonts'] ?? '';
		if ( ! $families ) return '';
		return 'https://fonts.googleapis.com/css2?family=' . $families . '&display=swap';
	}

	// ── Profile resolution ────────────────────────────────────────────────────

	/**
	 * Resolve the visual_style slug from a profile, mapping legacy layout_preset values.
	 */
	public static function resolve_style( array $profile ): string {
		$style = sanitize_text_field( $profile['visual_style'] ?? '' );
		if ( in_array( $style, self::VALID_STYLES, true ) ) {
			return $style;
		}
		$preset = sanitize_text_field( $profile['layout_preset'] ?? '' );
		return self::PRESET_STYLE_MAP[ $preset ] ?? 'professional';
	}

	/**
	 * Resolve and merge color palette from profile (theme + per-post hex overrides).
	 */
	public static function resolve_palette( array $profile ): array {
		$theme   = sanitize_text_field( $profile['color_theme'] ?? 'blue' );
		$palette = self::get_color_theme_palette( $theme );

		foreach ( [ 'accent', 'text', 'muted', 'card', 'surface', 'border' ] as $key ) {
			$saved = sanitize_hex_color( (string) ( $profile[ $key ] ?? '' ) );
			if ( $saved ) {
				$palette[ $key ] = $saved;
			}
		}
		return $palette;
	}

	// ── Main CSS generator ───────────────────────────────────────────────────

	/**
	 * Generate the complete optimized CSS string from a saved design profile.
	 *
	 * @param array $profile Full design profile from post meta or site option.
	 * @return string CSS ready for inline <style> injection.
	 */
	public static function generate_optimization_css( array $profile ): string {
		$style   = self::resolve_style( $profile );
		$palette = self::resolve_palette( $profile );
		$tokens  = self::get_visual_style_tokens( $style );
		$page_wide = ! empty( $profile['page_wide'] );

		// Computed helpers
		$p = array_merge( $tokens, $palette );
		$p['accent_dark']   = self::darken_hex( $palette['accent'], 22 );
		$p['primary_dark']  = self::darken_hex( $palette['primary'], 15 );
		$p['cta_text']      = self::get_contrast_text( $palette['cta'] );
		$p['accent_bg']     = self::hex_to_rgba( $palette['accent'], '0.07' );
		$p['accent_mid']    = self::hex_to_rgba( $palette['accent'], '0.15' );
		$p['primary_bg']    = self::hex_to_rgba( $palette['primary'], '0.06' );
		$p['shadow_accent'] = self::hex_to_rgba( $palette['accent'], '0.25' );

		$css  = "/* ── Gleo UI Optimization — {$style} / {$p['cta']} ── */\n\n";
		$css .= self::css_design_tokens( $p );
		$css .= self::css_typography( $p );
		$css .= self::css_layout( $p );
		$css .= self::css_images( $p );
		$css .= self::css_cards( $p );
		$css .= self::css_component_wrappers( $p );
		$css .= self::css_mobile( $p );

		if ( $page_wide ) {
			$css .= self::css_navigation( $p );
			$css .= self::css_buttons( $p );
			$css .= self::css_forms( $p );
			$css .= self::css_cta_sections( $p );
			$css .= self::css_page_wide_colors( $p );
		}

		return $css;
	}

	// ── CSS modules ──────────────────────────────────────────────────────────

	/**
	 * Set CSS custom properties on :root so all modules can reference them.
	 */
	private static function css_design_tokens( array $p ): string {
		return <<<CSS

/* ── 1. Design Tokens ── */
:root {
  --gleo-font-heading: {$p['font_heading']};
  --gleo-font-body: {$p['font_body']};
  --gleo-radius: {$p['radius']};
  --gleo-radius-sm: {$p['radius_sm']};
  --gleo-radius-lg: {$p['radius_lg']};
  --gleo-shadow-sm: {$p['shadow_sm']};
  --gleo-shadow-md: {$p['shadow_md']};
  --gleo-shadow-lg: {$p['shadow_lg']};
  --gleo-section-pad: {$p['section_padding']};
  --gleo-content-w: {$p['content_width']};
  --gleo-accent: {$p['accent']};
  --gleo-accent-dark: {$p['accent_dark']};
  --gleo-accent-bg: {$p['accent_bg']};
  --gleo-primary: {$p['primary']};
  --gleo-cta: {$p['cta']};
  --gleo-cta-hover: {$p['cta_hover']};
  --gleo-cta-text: {$p['cta_text']};
  --gleo-text: {$p['text']};
  --gleo-muted: {$p['muted']};
  --gleo-card: {$p['card']};
  --gleo-surface: {$p['surface']};
  --gleo-border: {$p['border']};
}

CSS;
	}

	/**
	 * Typography: font families, heading scale, body size, line-height, lead paragraph.
	 * This is the most impactful non-color module.
	 */
	private static function css_typography( array $p ): string {
		return <<<CSS

/* ── 2. Typography ── */
/* Body & content font */
body,
.entry-content, .wp-block-post-content, .post-content,
article.post, article.page {
  font-family: {$p['font_body']} !important;
  font-size: {$p['body_size']} !important;
  line-height: {$p['body_lh']} !important;
  color: {$p['text']} !important;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Heading font */
h1, h2, h3, h4, h5, h6,
.entry-content h1, .entry-content h2, .entry-content h3,
.entry-content h4, .entry-content h5, .entry-content h6,
.wp-block-post-content h1, .wp-block-post-content h2,
.wp-block-post-content h3, .wp-block-post-content h4 {
  font-family: {$p['font_heading']} !important;
  line-height: 1.18 !important;
  color: {$p['text']} !important;
}

/* H1 — Hero headline */
h1,
.entry-content h1, .wp-block-post-content h1,
.wp-block-heading.has-large-font-size {
  font-size: {$p['h1_size']} !important;
  font-weight: {$p['font_weight_h1']} !important;
  letter-spacing: {$p['h1_spacing']} !important;
  line-height: 1.1 !important;
  margin-bottom: 1.25rem !important;
}

/* H2 — Section headings */
h2,
.entry-content h2, .wp-block-post-content h2,
.wp-block-heading {
  font-size: {$p['h2_size']} !important;
  font-weight: {$p['font_weight_heading']} !important;
  letter-spacing: {$p['h2_spacing']} !important;
  line-height: 1.2 !important;
  margin-top: {$p['h2_margin_top']} !important;
  margin-bottom: 1rem !important;
  color: {$p['text']} !important;
}

/* H3 — Subsections */
h3,
.entry-content h3, .wp-block-post-content h3 {
  font-size: {$p['h3_size']} !important;
  font-weight: {$p['font_weight_heading']} !important;
  letter-spacing: {$p['h3_spacing']} !important;
  line-height: 1.25 !important;
  margin-top: {$p['h3_margin_top']} !important;
  margin-bottom: 0.6rem !important;
}

/* H4 */
h4,
.entry-content h4, .wp-block-post-content h4 {
  font-size: {$p['h4_size']} !important;
  font-weight: 600 !important;
  color: {$p['muted']} !important;
  margin-top: 1.5rem !important;
  margin-bottom: 0.4rem !important;
  text-transform: uppercase !important;
  letter-spacing: 0.06em !important;
}

/* Paragraphs */
.entry-content p, .wp-block-post-content p, .post-content p {
  margin-bottom: {$p['paragraph_gap']} !important;
  line-height: {$p['body_lh']} !important;
}

/* Lead paragraph — opening sentence gets emphasis */
.entry-content > p:first-of-type,
.wp-block-post-content > .wp-block-paragraph:first-of-type,
.entry-content > .wp-block-paragraph:first-of-type {
  font-size: 1.125em !important;
  line-height: 1.72 !important;
  color: {$p['muted']} !important;
}

/* Lists */
.entry-content ul, .wp-block-post-content ul,
.entry-content ol, .wp-block-post-content ol {
  padding-left: 1.875rem !important;
  margin-bottom: 1.35rem !important;
}
.entry-content li, .wp-block-post-content li {
  margin-bottom: 0.5rem !important;
  line-height: 1.7 !important;
}

/* Blockquotes */
.entry-content blockquote, .wp-block-post-content blockquote,
.entry-content .wp-block-quote, .wp-block-post-content .wp-block-quote {
  border-left: 4px solid {$p['accent']} !important;
  margin: 2rem 0 !important;
  padding: 1.125rem 1.5rem !important;
  background: {$p['surface']} !important;
  border-radius: 0 {$p['radius']} {$p['radius']} 0 !important;
  font-style: italic;
}
.entry-content .wp-block-quote p, .wp-block-post-content .wp-block-quote p,
.entry-content blockquote p, .wp-block-post-content blockquote p {
  color: {$p['muted']} !important;
  font-size: 1.05em !important;
  margin-bottom: 0 !important;
  line-height: 1.7 !important;
}

/* Tables */
.entry-content table, .wp-block-post-content table {
  width: 100% !important;
  border-collapse: collapse !important;
  font-size: 0.9375em !important;
  margin: 1.75rem 0 !important;
  border-radius: {$p['radius']} !important;
  overflow: hidden !important;
}
.entry-content th, .wp-block-post-content th {
  background: {$p['surface']} !important;
  color: {$p['text']} !important;
  font-family: {$p['font_heading']} !important;
  font-weight: 600 !important;
  padding: 0.8rem 1.125rem !important;
  text-align: left !important;
  border-bottom: 2px solid {$p['border']} !important;
}
.entry-content td, .wp-block-post-content td {
  padding: 0.7rem 1.125rem !important;
  border-bottom: 1px solid {$p['border']} !important;
  vertical-align: top;
}
.entry-content tr:last-child td, .wp-block-post-content tr:last-child td {
  border-bottom: none !important;
}

/* Inline code */
.entry-content code:not([class]), .wp-block-post-content code:not([class]) {
  background: {$p['surface']} !important;
  border: 1px solid {$p['border']} !important;
  border-radius: {$p['radius_sm']} !important;
  padding: 0.15em 0.4em !important;
  font-size: 0.875em !important;
  color: {$p['primary']} !important;
}

/* Horizontal rules */
.entry-content hr, .wp-block-post-content hr,
.entry-content .wp-block-separator, .wp-block-post-content .wp-block-separator {
  border: none !important;
  border-top: 2px solid {$p['border']} !important;
  margin: 2.75rem auto !important;
  max-width: 120px;
  opacity: 1 !important;
}

CSS;
	}

	/**
	 * Layout: content container, section spacing, visual balance.
	 */
	private static function css_layout( array $p ): string {
		return <<<CSS

/* ── 3. Layout & Spacing ── */
.entry-content, .wp-block-post-content, .post-content,
article.post .entry-content, article.page .entry-content {
  max-width: {$p['content_width']} !important;
  margin-left: auto !important;
  margin-right: auto !important;
  padding-left: 1.25rem !important;
  padding-right: 1.25rem !important;
}

/* Section separation for semantic blocks */
.entry-content > .wp-block-group,
.wp-block-post-content > .wp-block-group {
  margin-top: 2.5rem !important;
  margin-bottom: 2.5rem !important;
}

/* Columns: consistent gap and vertical rhythm */
.entry-content .wp-block-columns,
.wp-block-post-content .wp-block-columns {
  gap: 2rem !important;
  margin-top: 2rem !important;
  margin-bottom: 2rem !important;
}

/* Wide/full-width alignment — break out of content width safely */
.entry-content .alignwide, .wp-block-post-content .alignwide {
  max-width: calc({$p['content_width']} + 12rem) !important;
  margin-left: auto !important;
  margin-right: auto !important;
}
.entry-content .alignfull, .wp-block-post-content .alignfull {
  max-width: 100vw !important;
  margin-left: calc(-50vw + 50%) !important;
  margin-right: calc(-50vw + 50%) !important;
}

/* Figcaptions */
.entry-content figcaption, .wp-block-post-content figcaption,
.entry-content .wp-element-caption, .wp-block-post-content .wp-element-caption {
  text-align: center !important;
  font-size: 0.8125em !important;
  color: {$p['muted']} !important;
  margin-top: 0.5rem !important;
  font-style: italic;
}

CSS;
	}

	/**
	 * Images: polish presentation with consistent radius and shadow.
	 */
	private static function css_images( array $p ): string {
		return <<<CSS

/* ── 4. Images ── */
.entry-content img:not([class*='emoji']):not([class*='avatar']):not([class*='logo']):not([class*='icon']):not([class*='gleo']),
.wp-block-post-content img:not([class*='emoji']):not([class*='avatar']):not([class*='logo']):not([class*='icon']):not([class*='gleo']) {
  border-radius: {$p['radius']} !important;
  box-shadow: {$p['shadow_md']} !important;
  display: block !important;
  margin-top: 1.875rem !important;
  margin-bottom: 1.875rem !important;
  margin-left: auto !important;
  margin-right: auto !important;
  max-width: 100% !important;
  height: auto !important;
}
.entry-content .wp-block-image figure,
.wp-block-post-content .wp-block-image figure {
  margin-top: 1.875rem !important;
  margin-bottom: 1.875rem !important;
}

CSS;
	}

	/**
	 * Cards: gleo-ui-card and gleo-ui-grid layout patterns.
	 */
	private static function css_cards( array $p ): string {
		return <<<CSS

/* ── 5. Cards ── */
/* Grid wrapper produced by the component enhancement engine */
.gleo-ui-grid {
  display: grid !important;
  gap: 1.5rem !important;
  margin-top: 1.875rem !important;
  margin-bottom: 1.875rem !important;
}
.gleo-ui-grid.cols-2 { grid-template-columns: repeat(2, 1fr) !important; }
.gleo-ui-grid.cols-3 { grid-template-columns: repeat(3, 1fr) !important; }
.gleo-ui-grid.cols-4 { grid-template-columns: repeat(4, 1fr) !important; }

.gleo-ui-card {
  background: {$p['card']} !important;
  border: 1px solid {$p['border']} !important;
  border-radius: {$p['card_radius']} !important;
  padding: {$p['card_padding']} !important;
  box-shadow: {$p['shadow_sm']} !important;
  transition: box-shadow 0.2s, transform 0.2s !important;
}
.gleo-ui-card:hover {
  box-shadow: {$p['shadow_md']} !important;
  transform: translateY(-2px) !important;
}
.gleo-ui-card h3, .gleo-ui-card h4 {
  margin-top: 0 !important;
  font-size: 1.1rem !important;
  font-family: {$p['font_heading']} !important;
  font-weight: {$p['font_weight_heading']} !important;
  color: {$p['text']} !important;
}
.gleo-ui-card p {
  color: {$p['muted']} !important;
  margin-bottom: 0 !important;
  font-size: 0.9375rem !important;
  line-height: 1.65 !important;
}

CSS;
	}

	/**
	 * Component wrapper CSS: hero, features, testimonials, stats, CTA, trust, FAQ.
	 */
	private static function css_component_wrappers( array $p ): string {
		return <<<CSS

/* ── 6. Component Wrappers ── */

/* Hero section */
.gleo-ui-section.gleo-ui-hero {
  text-align: center !important;
  padding: {$p['hero_padding']} !important;
  background: linear-gradient(135deg, {$p['surface']} 0%, {$p['card']} 100%) !important;
  border-radius: {$p['radius_lg']} !important;
  margin-bottom: 3rem !important;
}
.gleo-ui-hero h1, .gleo-ui-hero h2 {
  font-size: {$p['h1_size']} !important;
  margin-top: 0 !important;
}
.gleo-ui-hero > p:first-of-type {
  font-size: 1.2em !important;
  max-width: 560px !important;
  margin-left: auto !important;
  margin-right: auto !important;
  color: {$p['muted']} !important;
}

/* Feature grid */
.gleo-ui-section.gleo-ui-features {
  padding: 2.5rem 0 !important;
}
.gleo-ui-features .gleo-ui-grid { gap: 1.75rem !important; }

/* Service cards — convert lists/sections to card grids */
.gleo-ui-section.gleo-ui-cards {
  padding: 2rem 0 !important;
}

/* Testimonials */
.gleo-ui-section.gleo-ui-testimonials {
  padding: 3rem 1.5rem !important;
  background: {$p['surface']} !important;
  border-radius: {$p['radius_lg']} !important;
  margin-top: 2.5rem !important;
  margin-bottom: 2.5rem !important;
}
.gleo-ui-testimonials blockquote,
.gleo-ui-testimonials .wp-block-quote {
  background: {$p['card']} !important;
  border-left: none !important;
  border-radius: {$p['card_radius']} !important;
  box-shadow: {$p['shadow_sm']} !important;
  padding: 1.5rem 1.75rem !important;
  margin: 0 !important;
}

/* Statistics section */
.gleo-ui-section.gleo-ui-stats {
  padding: 3rem 0 !important;
  text-align: center !important;
}
.gleo-ui-stats .gleo-ui-grid { gap: 1.25rem !important; }
.gleo-ui-stats .gleo-ui-stat-number {
  font-family: {$p['font_heading']} !important;
  font-size: clamp(2rem, 6vw, 3rem) !important;
  font-weight: {$p['font_weight_h1']} !important;
  color: {$p['accent']} !important;
  line-height: 1 !important;
  display: block !important;
}
.gleo-ui-stats .gleo-ui-stat-label {
  font-size: 0.9rem !important;
  color: {$p['muted']} !important;
  display: block !important;
  margin-top: 0.25rem !important;
}

/* Trust elements */
.gleo-ui-section.gleo-ui-trust {
  padding: 2rem 0 !important;
  border-top: 1px solid {$p['border']} !important;
  border-bottom: 1px solid {$p['border']} !important;
  margin: 2.5rem 0 !important;
}

/* FAQ — style pass-through for existing Gleo accordion JS */
.gleo-ui-section.gleo-ui-faq {
  padding: 2rem 0 !important;
}

/* CTA banner */
.gleo-ui-section.gleo-ui-cta {
  text-align: {$p['cta_text_align']} !important;
  padding: {$p['cta_padding']} !important;
  background: {$p['cta']} !important;
  border-radius: {$p['radius_lg']} !important;
  margin: 3rem 0 !important;
  box-shadow: {$p['shadow_md']} !important;
}
.gleo-ui-cta h2, .gleo-ui-cta h3,
.gleo-ui-cta p, .gleo-ui-cta a {
  color: {$p['cta_text']} !important;
}
.gleo-ui-cta h2 { margin-top: 0 !important; border: none !important; background: none !important; padding: 0 !important; }
.gleo-ui-cta p { opacity: 0.9; }
.gleo-ui-cta .wp-block-button__link,
.gleo-ui-cta a.button, .gleo-ui-cta input[type="submit"],
.gleo-ui-cta button[type="submit"] {
  background: {$p['cta_text']} !important;
  color: {$p['cta']} !important;
  border-color: {$p['cta_text']} !important;
  margin-top: 1.25rem !important;
}
.gleo-ui-cta .wp-block-button__link:hover {
  background: rgba(255,255,255,0.9) !important;
}

CSS;
	}

	/**
	 * Navigation: spacing, hover states, active indicators. Page-wide.
	 */
	private static function css_navigation( array $p ): string {
		return <<<CSS

/* ── 7. Navigation ── */
.main-navigation, .primary-menu-container, nav.site-navigation,
#site-navigation, .nav-primary, .header-nav {
  display: flex !important;
  align-items: center !important;
}

/* Nav links */
.main-navigation a, .nav-menu a, .menu-item > a,
header nav a, .site-nav a, #primary-menu a,
.main-navigation ul li a {
  font-family: {$p['font_heading']} !important;
  font-weight: 500 !important;
  font-size: 0.9375rem !important;
  padding: {$p['nav_link_padding']} !important;
  border-radius: {$p['nav_link_radius']} !important;
  text-decoration: none !important;
  transition: background 0.15s, color 0.15s !important;
  color: {$p['text']} !important;
}

/* Hover state */
.main-navigation a:hover, .nav-menu a:hover, .menu-item > a:hover,
header nav a:hover, .site-nav a:hover {
  background: {$p['accent_bg']} !important;
  color: {$p['accent']} !important;
}

/* Active / current page */
.main-navigation .current-menu-item > a,
.main-navigation .current-page-ancestor > a,
.nav-menu .current-menu-item > a,
.main-navigation .current_page_item > a {
  background: {$p['accent_bg']} !important;
  color: {$p['accent']} !important;
  font-weight: 600 !important;
}

/* Site header shadow */
.site-header, header.site-header, #masthead {
  box-shadow: {$p['shadow_sm']} !important;
}

/* Sub-menus */
.main-navigation .sub-menu, .nav-menu .sub-menu {
  background: {$p['card']} !important;
  border: 1px solid {$p['border']} !important;
  border-radius: {$p['radius']} !important;
  box-shadow: {$p['shadow_md']} !important;
  padding: 0.375rem !important;
}
.main-navigation .sub-menu a, .nav-menu .sub-menu a {
  border-radius: {$p['radius_sm']} !important;
}

CSS;
	}

	/**
	 * Buttons: padding, radius, min-height, font, transition. Page-wide.
	 */
	private static function css_buttons( array $p ): string {
		return <<<CSS

/* ── 8. Buttons ── */
/* Base styling for all button patterns */
.wp-block-button__link,
.wp-block-button .wp-block-button__link,
a.button, a.btn, input.button, .btn,
.button:not(.gleo-btn), a[class*="button-"]:not([class*="gleo"]) {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: {$p['btn_padding']} !important;
  border-radius: {$p['btn_radius']} !important;
  font-family: {$p['font_heading']} !important;
  font-weight: 600 !important;
  font-size: 0.9375rem !important;
  letter-spacing: 0.01em !important;
  line-height: 1.4 !important;
  text-decoration: none !important;
  cursor: pointer !important;
  min-height: 44px !important;
  transition: transform 0.14s, box-shadow 0.14s, background-color 0.14s, border-color 0.14s !important;
  background-color: {$p['cta']} !important;
  color: {$p['cta_text']} !important;
  border: 2px solid {$p['cta']} !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.12) !important;
}
.wp-block-button__link:hover,
a.button:hover, a.btn:hover {
  background-color: {$p['cta_hover']} !important;
  border-color: {$p['cta_hover']} !important;
  transform: translateY(-1px) !important;
  box-shadow: 0 4px 12px {$p['shadow_accent']} !important;
}

/* Outline/secondary buttons */
.wp-block-button.is-style-outline .wp-block-button__link {
  background-color: transparent !important;
  color: {$p['accent']} !important;
  border-color: {$p['accent']} !important;
  box-shadow: none !important;
}
.wp-block-button.is-style-outline .wp-block-button__link:hover {
  background-color: {$p['accent_bg']} !important;
  transform: translateY(-1px) !important;
}

/* Form submit buttons */
input[type="submit"], button[type="submit"], .wpcf7-submit {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: {$p['btn_padding']} !important;
  border-radius: {$p['btn_radius']} !important;
  font-family: {$p['font_heading']} !important;
  font-weight: 600 !important;
  font-size: 0.9375rem !important;
  min-height: 44px !important;
  cursor: pointer !important;
  transition: background-color 0.14s, transform 0.14s, box-shadow 0.14s !important;
  background-color: {$p['cta']} !important;
  color: {$p['cta_text']} !important;
  border: 2px solid {$p['cta']} !important;
}
input[type="submit"]:hover, button[type="submit"]:hover, .wpcf7-submit:hover {
  background-color: {$p['cta_hover']} !important;
  border-color: {$p['cta_hover']} !important;
  transform: translateY(-1px) !important;
  box-shadow: 0 4px 12px {$p['shadow_accent']} !important;
}

CSS;
	}

	/**
	 * Forms: input sizing, focus rings, label hierarchy. Page-wide.
	 */
	private static function css_forms( array $p ): string {
		return <<<CSS

/* ── 9. Forms ── */
/* Labels */
label, .gform_label, .wpcf7 label,
.wpcf7-form label, form label {
  display: block !important;
  font-family: {$p['font_heading']} !important;
  font-weight: 600 !important;
  font-size: 0.875rem !important;
  color: {$p['text']} !important;
  margin-bottom: 0.4375rem !important;
}

/* Inputs */
input[type="text"], input[type="email"], input[type="tel"],
input[type="url"], input[type="search"], input[type="number"],
input[type="date"], input[type="password"], textarea, select,
.wpcf7-text, .wpcf7-textarea, .wpcf7-select, .wpcf7-email,
.wpcf7-tel, .gfield input, .gfield textarea, .gfield select {
  display: block !important;
  width: 100% !important;
  padding: 0.8125rem 1rem !important;
  border: 1.5px solid {$p['border']} !important;
  border-radius: {$p['input_radius']} !important;
  font-family: {$p['font_body']} !important;
  font-size: 1rem !important;
  line-height: 1.5 !important;
  background: {$p['card']} !important;
  color: {$p['text']} !important;
  min-height: 44px !important;
  transition: border-color 0.15s, box-shadow 0.15s !important;
  -webkit-appearance: none !important;
  appearance: none !important;
}

input[type="text"]:focus, input[type="email"]:focus, input[type="tel"]:focus,
input[type="url"]:focus, input[type="search"]:focus, input[type="number"]:focus,
input[type="date"]:focus, input[type="password"]:focus, textarea:focus, select:focus,
.wpcf7-text:focus, .wpcf7-textarea:focus, .wpcf7-email:focus {
  outline: none !important;
  border-color: {$p['accent']} !important;
  box-shadow: 0 0 0 3px {$p['accent_bg']} !important;
}

/* Textarea */
textarea, .wpcf7-textarea {
  min-height: 130px !important;
  resize: vertical !important;
}

/* Form groups / field rows */
.wpcf7 p, .gfield, .gform_body .gfield {
  margin-bottom: 1.25rem !important;
}

/* Select arrow */
select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
  background-repeat: no-repeat !important;
  background-position: right 0.875rem center !important;
  padding-right: 2.5rem !important;
}

/* Placeholder */
::placeholder { color: {$p['muted']} !important; opacity: 0.7 !important; }

CSS;
	}

	/**
	 * CTA sections styling (used for page-wide application). Page-wide.
	 */
	private static function css_cta_sections( array $p ): string {
		return <<<CSS

/* ── 10. CTA Sections — page-wide emphasis ── */
/* WP Call to Action blocks */
.wp-block-cover, .wp-block-cover-image {
  border-radius: {$p['radius_lg']} !important;
  overflow: hidden !important;
}

/* Inline CTA-like patterns */
.entry-content .wp-block-group.has-background,
.wp-block-post-content .wp-block-group.has-background {
  border-radius: {$p['radius']} !important;
  padding: 2.5rem 2rem !important;
}

CSS;
	}

	/**
	 * Page-wide color application (links, headings, body). Page-wide only.
	 */
	private static function css_page_wide_colors( array $p ): string {
		return <<<CSS

/* ── 11. Page-wide Color System ── */
body { color: {$p['text']} !important; }
a { color: {$p['accent']} !important; transition: color 0.15s !important; }
a:hover, a:focus { color: {$p['accent_dark']} !important; }

/* Preserve nav and button link colors */
.wp-block-button__link, a.button, a.btn { color: {$p['cta_text']} !important; }

/* Headings */
h1, h2, h3, h4, h5, h6 { color: {$p['text']} !important; }

/* Sidebar widget titles */
.widget-title, .widgettitle {
  color: {$p['text']} !important;
  font-family: {$p['font_heading']} !important;
  border-bottom: 2px solid {$p['border']} !important;
  padding-bottom: 0.5rem !important;
}

/* Footer links */
.site-footer a, footer a { color: {$p['accent_light']} !important; }

CSS;
	}

	/**
	 * Mobile responsive overrides — touch targets, fluid spacing, readable type.
	 */
	private static function css_mobile( array $p ): string {
		return <<<CSS

/* ── 12. Mobile & Responsive ── */
@media (max-width: 768px) {
  .entry-content, .wp-block-post-content, .post-content {
    padding-left: 1rem !important;
    padding-right: 1rem !important;
    font-size: calc({$p['body_size']} * 0.95) !important;
  }

  /* Ensure all interactive elements are touch-friendly */
  a, button, input[type="submit"], input[type="button"],
  .wp-block-button__link, select, input[type="checkbox"],
  input[type="radio"] {
    min-height: 44px !important;
    min-width: 44px !important;
  }

  /* Readable headings on small screens (clamp handles this but add safety) */
  h1, .entry-content h1 { line-height: 1.15 !important; }
  h2, .entry-content h2 { margin-top: 2.25rem !important; }

  /* Cards: single column on mobile */
  .gleo-ui-grid.cols-2,
  .gleo-ui-grid.cols-3,
  .gleo-ui-grid.cols-4 {
    grid-template-columns: 1fr !important;
  }

  /* Columns: stack on mobile */
  .entry-content .wp-block-columns,
  .wp-block-post-content .wp-block-columns {
    flex-direction: column !important;
  }

  /* Hero: reduce padding */
  .gleo-ui-section.gleo-ui-hero {
    padding: 3rem 1.25rem !important;
  }

  /* CTA: reduce padding */
  .gleo-ui-section.gleo-ui-cta {
    padding: 3rem 1.25rem !important;
  }

  /* Testimonials: reduce padding */
  .gleo-ui-section.gleo-ui-testimonials {
    padding: 2rem 1rem !important;
  }

  /* Navigation: ensure touch-friendly */
  .main-navigation a, .nav-menu a, .menu-item > a {
    padding: 0.75rem 1rem !important;
    min-height: 44px !important;
    display: flex !important;
    align-items: center !important;
  }

  /* Inputs */
  input[type="text"], input[type="email"], input[type="tel"],
  input[type="url"], textarea, select, .wpcf7-text, .wpcf7-email {
    font-size: 16px !important; /* Prevent iOS zoom on focus */
    min-height: 48px !important;
  }
}

@media (max-width: 480px) {
  .gleo-ui-grid.cols-2 {
    grid-template-columns: 1fr !important;
  }
}

CSS;
	}

	// ── Component wrapping ───────────────────────────────────────────────────

	/**
	 * Wrap detected content sections with semantic gleo-ui-* markup.
	 *
	 * This method processes post_content HTML, finds headings by label,
	 * and wraps their content until the next same-level heading.
	 *
	 * @param string $content     Raw post_content HTML.
	 * @param array  $enhancements AI enhancement list: [{ section_id, type, heading_label, enabled }]
	 * @return string Modified HTML with wrappers inserted.
	 */
	public static function wrap_sections_with_enhancements( string $content, array $enhancements ): string {
		if ( empty( $enhancements ) || empty( $content ) ) {
			return $content;
		}

		// Only wrap enabled enhancements, sorted by confidence desc.
		$active = array_filter( $enhancements, fn( $e ) => ! empty( $e['enabled'] ) );
		if ( empty( $active ) ) {
			return $content;
		}

		foreach ( $active as $enhancement ) {
			$type  = sanitize_text_field( $enhancement['type'] ?? '' );
			$label = sanitize_text_field( $enhancement['heading_label'] ?? '' );

			if ( ! $type || ! $label ) continue;

			$content = self::wrap_section_by_heading( $content, $label, $type );
		}

		return $content;
	}

	/**
	 * Locate a heading by text and wrap the section until the next same-level heading.
	 */
	private static function wrap_section_by_heading( string $content, string $heading_label, string $type ): string {
		$css_class = self::get_wrapper_class( $type );
		if ( ! $css_class ) return $content;

		$escaped_label = preg_quote( $heading_label, '/' );

		// Match h2 or h3 containing the label text (case-insensitive, content may have HTML inside tag)
		$pattern = '/(<h([23])[^>]*>(?:[^<]|<(?!\/h[23]>))*?' . $escaped_label . '(?:[^<]|<(?!\/h[23]>))*?<\/h\2>)([\s\S]*?)(?=<h[23][^>]*>|$)/i';

		$content = preg_replace_callback( $pattern, function( $matches ) use ( $css_class, $type ) {
			$heading  = $matches[1];
			$body     = $matches[3];

			// Safety: skip wrapping if section body contains a form (avoid wrapping forms)
			if ( preg_match( '/<form\b/i', $body ) && preg_match( '/\bsubmit\b/i', $body ) ) {
				return $matches[0];
			}

			// Skip if already wrapped
			if ( strpos( $matches[0], 'gleo-ui-section' ) !== false ) {
				return $matches[0];
			}

			return '<div class="gleo-ui-section ' . esc_attr( $css_class ) . '" data-gleo-enhance="' . esc_attr( $type ) . '">'
				. $heading . $body
				. '</div>';
		}, $content, 1 );

		return $content ?? $content;
	}

	/**
	 * Map enhancement type to wrapper CSS class string.
	 */
	private static function get_wrapper_class( string $type ): string {
		$map = [
			'hero_layout'       => 'gleo-ui-hero',
			'service_cards'     => 'gleo-ui-cards',
			'testimonial_layout'=> 'gleo-ui-testimonials',
			'faq_accordion'     => 'gleo-ui-faq',
			'cta_banner'        => 'gleo-ui-cta',
			'feature_grid'      => 'gleo-ui-features',
			'stats_section'     => 'gleo-ui-stats',
			'trust_elements'    => 'gleo-ui-trust',
		];
		return $map[ $type ] ?? '';
	}

	// ── Color utilities ──────────────────────────────────────────────────────

	/**
	 * Darken a hex color by subtracting from each RGB channel.
	 */
	public static function darken_hex( string $hex, int $amount = 20 ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) return '#000000';
		return sprintf(
			'#%02x%02x%02x',
			max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount ),
			max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount ),
			max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount )
		);
	}

	/**
	 * Convert hex to rgba string.
	 */
	public static function hex_to_rgba( string $hex, string $alpha ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) return "rgba(59,130,246,{$alpha})";
		return sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
	}

	/**
	 * Return white or dark text depending on background luminance (WCAG).
	 */
	public static function get_contrast_text( string $bg_hex ): string {
		$hex = ltrim( $bg_hex, '#' );
		if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		if ( strlen( $hex ) !== 6 ) return '#ffffff';
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		$r = $r <= 0.03928 ? $r / 12.92 : ( ( $r + 0.055 ) / 1.055 ) ** 2.4;
		$g = $g <= 0.03928 ? $g / 12.92 : ( ( $g + 0.055 ) / 1.055 ) ** 2.4;
		$b = $b <= 0.03928 ? $b / 12.92 : ( ( $b + 0.055 ) / 1.055 ) ** 2.4;
		$luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		return $luminance > 0.35 ? '#0f172a' : '#ffffff';
	}
}
