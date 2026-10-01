<?php
/**
 * Sidebar vehicle filter widget (Make + Model) for RWBE Product Importer.
 *
 * Adds a WooCommerce-sidebar widget with two dropdowns — vehicle make and model —
 * that filter the shop/catalog by the pa_make / pa_model product attributes
 * populated by the importer. The model list is loaded via AJAX for the selected
 * make. Selecting a model reuses the SAME query vars already handled by
 * RWBE_Product_Importer_Search::filter_product_query (rwbe_make / rwbe_model), so no
 * existing behaviour is changed.
 *
 * Everything here is namespaced with the `rwbe-vf-` / `rwbe_vf_` prefix and its own
 * assets, so it never collides with the [rwbe_ymm_search] shortcode.
 *
 * @since 1.2.0
 * @package RWBE_Product_Importer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Controller: registers the widget, its front-end assets and the AJAX endpoint.
 */
/**
 * Controller for the vehicle filter widget and block.
 */
class RWBE_Vehicle_Filter {

	/** Query var names (shared with the existing search so filtering just works) */
	const QV_MAKE  = 'rwbe_make';
	const QV_MODEL = 'rwbe_model';

	/** Widget base id */
	const WIDGET_BASE = 'rwbe_vehicle_filter';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// AJAX: models available for a selected make (logged-in and guests)
		add_action( 'wp_ajax_rwbe_vf_get_models', array( $this, 'ajax_get_models' ) );
		add_action( 'wp_ajax_nopriv_rwbe_vf_get_models', array( $this, 'ajax_get_models' ) );

		// Invalidate the cached make→model lists after an import completes.
		add_action( 'rwbe_product_import_cron', array( $this, 'flush_model_cache' ), 99 );

		// Register the Gutenberg block so it shows up (searchable) in the block
		// inserter / block-based Widgets screen, not just as a legacy widget.
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the dynamic "RWBE — Filtro por Veículo" block.
	 *
	 * Server-rendered (render_callback), so no JS build step is needed for the
	 * front end. A tiny editor script provides the inserter entry + live preview.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
			// Older WP without block support — legacy widget still works.
		}

		wp_register_script(
			'rwbe-vehicle-filter-block',
			RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/js/vehicle-filter-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			RWBE_PRODUCT_IMPORTER_VERSION,
			true
		);

		register_block_type(
			'rwbe/vehicle-filter',
			array(
				'api_version'     => 2,
				'title'           => __( 'RWBE — Filtro por Veículo (Marca + Modelo)', 'rwbe-product-importer' ),
				'description'     => __( 'Filtra a loja por marca e modelo de veículo.', 'rwbe-product-importer' ),
				'category'        => 'widgets',
				'icon'            => 'car',
				'keywords'        => array( 'rwbe', 'veículo', 'marca', 'modelo', 'filtro' ),
				'editor_script'   => 'rwbe-vehicle-filter-block',
				'style'           => 'rwbe-vehicle-filter',
				'script'          => 'rwbe-vehicle-filter',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'make_label'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'model_label' => array(
						'type'    => 'string',
						'default' => '',
					),
					'shop_url'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Block server render.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return self::render_form( is_array( $attributes ) ? $attributes : array() );
	}

	/**
	 * Vehicle makes with at least one published product.
	 *
	 * @return array Term objects.
	 */
	public static function get_makes() {
		if ( ! taxonomy_exists( 'pa_make' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'pa_make',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Render the vehicle filter form. Shared by the widget and the block.
	 *
	 * @param array $opts make_label, model_label, shop_url.
	 * @return string HTML
	 */
	public static function render_form( $opts = array() ) {
		// Ensure front assets load wherever the form appears.
		self::enqueue_assets();

		// Build the static map in the background if it is not there yet.
		if ( class_exists( 'RWBE_Vehicle_Map' ) ) {
			RWBE_Vehicle_Map::maybe_schedule_rebuild();
		}

		$make_label  = ! empty( $opts['make_label'] ) ? $opts['make_label'] : __( 'Marca', 'rwbe-product-importer' );
		$model_label = ! empty( $opts['model_label'] ) ? $opts['model_label'] : __( 'Modelo', 'rwbe-product-importer' );
		$shop_url    = ! empty( $opts['shop_url'] )
			? $opts['shop_url']
			: ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) );

		if ( ! taxonomy_exists( 'pa_make' ) ) {
			return '<p class="rwbe-vf-empty">' . esc_html__( 'A pesquisa por veículo ainda não está disponível.', 'rwbe-product-importer' ) . '</p>';
		}

		$makes = self::get_makes();
		if ( empty( $makes ) ) {
			return '<p class="rwbe-vf-empty">' . esc_html__( 'Ainda não existem marcas de veículo importadas.', 'rwbe-product-importer' ) . '</p>';
		}

		$uid        = 'rwbe-vf-' . uniqid();
		$sel_make   = isset( $_GET[ self::QV_MAKE ] ) ? sanitize_title( wp_unslash( $_GET[ self::QV_MAKE ] ) ) : '';
		$sel_model  = isset( $_GET[ self::QV_MODEL ] ) ? sanitize_title( wp_unslash( $_GET[ self::QV_MODEL ] ) ) : '';
		$has_filter = ( $sel_make !== '' || $sel_model !== '' );
		$accent     = self::get_accent_color();

		ob_start();
		?>
		<form class="rwbe-vf-form" method="get" action="<?php echo esc_url( $shop_url ); ?>" role="search" aria-label="<?php esc_attr_e( 'Filtro de peças por veículo', 'rwbe-product-importer' ); ?>" style="--rwbe-vf-accent: <?php echo esc_attr( $accent ); ?>;">
			<div class="rwbe-vf-field">
				<label class="rwbe-vf-label" for="<?php echo esc_attr( $uid . '-make' ); ?>"><?php echo esc_html( $make_label ); ?></label>
				<div class="rwbe-vf-select">
					<select id="<?php echo esc_attr( $uid . '-make' ); ?>" class="rwbe-vf-make" name="<?php echo esc_attr( self::QV_MAKE ); ?>" aria-label="<?php echo esc_attr( $make_label ); ?>">
						<option value=""><?php echo esc_html( $make_label ); ?></option>
						<?php foreach ( $makes as $make ) : ?>
							<option value="<?php echo esc_attr( $make->slug ); ?>" <?php selected( $sel_make, $make->slug ); ?>><?php echo esc_html( $make->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="rwbe-vf-field">
				<label class="rwbe-vf-label" for="<?php echo esc_attr( $uid . '-model' ); ?>"><?php echo esc_html( $model_label ); ?></label>
				<div class="rwbe-vf-select">
					<select id="<?php echo esc_attr( $uid . '-model' ); ?>" class="rwbe-vf-model" name="<?php echo esc_attr( self::QV_MODEL ); ?>" data-selected="<?php echo esc_attr( $sel_model ); ?>" aria-label="<?php echo esc_attr( $model_label ); ?>" <?php disabled( $sel_make, '' ); ?>>
						<option value=""><?php echo esc_html( $model_label ); ?></option>
					</select>
				</div>
			</div>
			<?php if ( $has_filter ) : ?>
				<a class="rwbe-vf-reset" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Limpar filtro', 'rwbe-product-importer' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Clear cached make→model lists (called after an import).
	 *
	 * Bumping the key namespace invalidates everything at once and keeps working
	 * when the transients live in a persistent object cache; the DELETE is only
	 * housekeeping for the wp_options-backed case.
	 */
	public function flush_model_cache() {
		global $wpdb;

		update_option( 'rwbe_vf_cache_version', self::cache_version() + 1, false );

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_rwbe_vf_models\_%'
                OR option_name LIKE '\_transient\_timeout\_rwbe_vf_models\_%'"
		);
	}

	/**
	 * Current cache-key namespace. Bumped by flush_model_cache() after an import.
	 *
	 * @return int
	 */
	private static function cache_version() {
		return (int) get_option( 'rwbe_vf_cache_version', 1 );
	}

	/**
	 * Register the widget class with WordPress.
	 */
	public function register_widget() {
		register_widget( 'RWBE_Vehicle_Filter_Widget' );
	}

	/**
	 * Register (and conditionally enqueue) the widget assets.
	 */
	public function register_assets() {
		wp_register_style(
			'rwbe-vehicle-filter',
			RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/css/vehicle-filter.css',
			array(),
			RWBE_PRODUCT_IMPORTER_VERSION
		);
		wp_register_script(
			'rwbe-vehicle-filter',
			RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/js/vehicle-filter.js',
			array( 'jquery' ),
			RWBE_PRODUCT_IMPORTER_VERSION,
			true
		);
		// Enqueue when the widget is active in any sidebar, or on the shop/catalog
		// pages (where a themed sidebar might render it via a block area).
		$on_shop = function_exists( 'is_shop' ) && ( is_shop() || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) );
		if ( is_active_widget( false, false, self::WIDGET_BASE ) || $on_shop ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the filter assets, localising them on the way.
	 *
	 * Built once, and only when the script is actually going out. Previously the
	 * localised data — a nonce plus RWBE_Vehicle_Map::get_urls(), i.e. an option read
	 * and two file_exists() — was assembled on every front-end request, including the
	 * pages that never show the filter.
	 */
	private static function enqueue_assets() {
		static $localized = false;

		if ( ! $localized ) {
			$localized = true;

			// Shared static map (same files the [rwbe_ymm_search] shortcode uses). The
			// AJAX endpoint stays as the fallback when the files are missing.
			$map = class_exists( 'RWBE_Vehicle_Map' )
				? RWBE_Vehicle_Map::get_urls()
				: array(
					'models' => '',
					'years'  => '',
				);

			wp_localize_script(
				'rwbe-vehicle-filter',
				'rwbeVF',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'rwbe_vf_nonce' ),
					'modelsUrl' => $map['models'],
					'i18n'      => array(
						'selectModel' => __( 'Selecione o modelo', 'rwbe-product-importer' ),
						'loading'     => __( 'A carregar…', 'rwbe-product-importer' ),
						'noModels'    => __( 'Sem modelos disponíveis', 'rwbe-product-importer' ),
					),
				)
			);
		}//end if

		wp_enqueue_style( 'rwbe-vehicle-filter' );
		wp_enqueue_script( 'rwbe-vehicle-filter' );
	}

	/**
	 * AJAX handler: return the models available for a given make.
	 */
	public function ajax_get_models() {
		check_ajax_referer( 'rwbe_vf_nonce', 'nonce' );

		$make = isset( $_POST['make'] ) ? sanitize_title( wp_unslash( $_POST['make'] ) ) : '';
		if ( $make === '' ) {
			wp_send_json_success( array( 'models' => array() ) );
		}

		wp_send_json_success( array( 'models' => $this->get_models_for_make( $make ) ) );
	}

	/**
	 * Distinct models available for a given make. Cached 12h.
	 *
	 * @param string $make_slug
	 * @return array List of ['slug' => ..., 'name' => ...]
	 */
	public function get_models_for_make( $make_slug ) {
		$cache_key = 'rwbe_vf_models_' . self::cache_version() . '_' . md5( $make_slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Prefer the fitment table (correct make→model pairing) when it is populated.
		$models = null;
		if ( class_exists( 'RWBE_Fitment' ) && RWBE_Fitment::is_ready() ) {
			$map    = RWBE_Fitment::get_model_map( $make_slug );
			$models = isset( $map[ $make_slug ] ) ? $map[ $make_slug ] : array();
		}
		if ( $models === null ) {
			$models = $this->get_related_terms( 'pa_model', array( 'pa_make' => $make_slug ) );
		}
		set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * Return distinct terms of $target_tax that co-occur on published products which
	 * also have every (taxonomy => slug) pair in $filters. Single indexed SQL query.
	 *
	 * @param string $target_tax         Taxonomy whose terms to return.
	 * @param array  $filters            Map of taxonomy => term slug the product must have.
	 * @param bool   $order_numeric_desc Sort by numeric value descending (years)
	 * @return array List of ['slug' => ..., 'name' => ...]
	 */
	private function get_related_terms( $target_tax, $filters, $order_numeric_desc = false ) {
		global $wpdb;

		if ( ! taxonomy_exists( $target_tax ) ) {
			return array();
		}
		foreach ( $filters as $tax => $slug ) {
			if ( ! taxonomy_exists( $tax ) || $slug === '' ) {
				return array();
			}
		}

		$joins  = '';
		$params = array( $target_tax );
		$i      = 0;
		foreach ( $filters as $tax => $slug ) {
			++$i;
			$joins   .= " INNER JOIN {$wpdb->term_relationships} trf{$i} ON trf{$i}.object_id = tr.object_id";
			$joins   .= " INNER JOIN {$wpdb->term_taxonomy} ttf{$i} ON ttf{$i}.term_taxonomy_id = trf{$i}.term_taxonomy_id AND ttf{$i}.taxonomy = %s";
			$joins   .= " INNER JOIN {$wpdb->terms} tf{$i} ON tf{$i}.term_id = ttf{$i}.term_id AND tf{$i}.slug = %s";
			$params[] = $tax;
			$params[] = $slug;
		}

		$order = $order_numeric_desc ? '(t.name + 0) DESC' : 't.name ASC';

		$sql = "SELECT DISTINCT t.slug, t.name
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
                INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                {$joins}
                INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
                ORDER BY {$order}";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$out[] = array(
					'slug' => $row->slug,
					'name' => $row->name,
				);
			}
		}
		return $out;
	}

	/**
	 * Configured search button colour (falls back to the brand red), shared with
	 * the existing search bar so both look consistent.
	 *
	 * @return string Hex colour
	 */
	public static function get_accent_color() {
		$color = get_option( 'rwbe_ymm_button_color', '#d6201f' );
		$color = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $color ) : $color;
		return $color ? $color : '#d6201f';
	}
}

/**
 * The sidebar widget itself.
 *
 * Kept in the same file as its controller on purpose: the widget is a thin shell that
 * delegates rendering to RWBE_Vehicle_Filter::render_form(), and the two are read
 * together.
 */
class RWBE_Vehicle_Filter_Widget extends WP_Widget {

	/**
	 * Register the widget with its id base, name and description.
	 */
	public function __construct() {
		parent::__construct(
			RWBE_Vehicle_Filter::WIDGET_BASE,
			__( 'RWBE — Filtro por Veículo (Marca + Modelo)', 'rwbe-product-importer' ),
			array(
				'description' => __( 'Filtra a loja por marca e modelo de veículo. Ideal para a barra lateral da página /loja.', 'rwbe-product-importer' ),
				'classname'   => 'rwbe-vehicle-filter-widget',
			)
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args (before/after widget & title).
	 * @param array $instance Saved widget settings.
	 */
	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Pesquisar por veículo', 'rwbe-product-importer' );
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		// The before/after markup is supplied by the theme's sidebar registration, not
		// by user input, and a widget is documented to echo it as-is; escaping it would
		// print the theme's own tags.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied markup.
		echo $args['before_widget'];

		if ( $title ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied markup; the title itself is escaped.
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}

		// Reuse the shared renderer (same markup as the block). It builds the whole form
		// itself and escapes every value it interpolates, so what comes back is already
		// safe HTML; the labels below are escaped inside it, not here.
		$form = RWBE_Vehicle_Filter::render_form(
			array(
				'make_label'  => ! empty( $instance['make_label'] ) ? $instance['make_label'] : '',
				'model_label' => ! empty( $instance['model_label'] ) ? $instance['model_label'] : '',
				'shop_url'    => ! empty( $instance['shop_url'] ) ? $instance['shop_url'] : '',
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_form() escapes every value it interpolates.
		echo $form;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied markup.
		echo $args['after_widget'];
	}

	/**
	 * Back-end settings form.
	 *
	 * @param array $instance Saved settings.
	 */
	public function form( $instance ) {
		$title       = isset( $instance['title'] ) ? $instance['title'] : __( 'Pesquisar por veículo', 'rwbe-product-importer' );
		$make_label  = isset( $instance['make_label'] ) ? $instance['make_label'] : __( 'Marca', 'rwbe-product-importer' );
		$model_label = isset( $instance['model_label'] ) ? $instance['model_label'] : __( 'Modelo', 'rwbe-product-importer' );
		$shop_url    = isset( $instance['shop_url'] ) ? $instance['shop_url'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Título:', 'rwbe-product-importer' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'make_label' ) ); ?>"><?php esc_html_e( 'Rótulo da marca:', 'rwbe-product-importer' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'make_label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'make_label' ) ); ?>" type="text" value="<?php echo esc_attr( $make_label ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'model_label' ) ); ?>"><?php esc_html_e( 'Rótulo do modelo:', 'rwbe-product-importer' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'model_label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'model_label' ) ); ?>" type="text" value="<?php echo esc_attr( $model_label ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'shop_url' ) ); ?>"><?php esc_html_e( 'URL de resultados (vazio = página da Loja):', 'rwbe-product-importer' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'shop_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'shop_url' ) ); ?>" type="text" value="<?php echo esc_attr( $shop_url ); ?>" placeholder="/loja/" />
		</p>
		<?php
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Previous settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance                = array();
		$instance['title']       = isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['make_label']  = isset( $new_instance['make_label'] ) ? sanitize_text_field( $new_instance['make_label'] ) : '';
		$instance['model_label'] = isset( $new_instance['model_label'] ) ? sanitize_text_field( $new_instance['model_label'] ) : '';
		$instance['shop_url']    = isset( $new_instance['shop_url'] ) ? esc_url_raw( trim( $new_instance['shop_url'] ) ) : '';
		return $instance;
	}
}
