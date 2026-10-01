<?php
/**
 * Generic helper class for attribute management
 *
 * @since 1.0.0
 */

// Exit when accessed directly: these files only make sense inside WordPress.
if (!defined('WPINC')) {
    die;
}

class RWBE_Generic_Attribute_Helper {

    /**
     * Generic method to ensure any product attribute exists in WooCommerce
     * This function creates the attribute if it doesn't exist
     * 
     * @param string $attribute_slug The attribute slug (without pa_ prefix)
     * @param string $attribute_label The attribute label for display
     * @param bool $hierarchical Whether the attribute is hierarchical
     * @return bool True if attribute exists or was created successfully, false otherwise
     */
    public static function ensure_attribute_exists($attribute_slug, $attribute_label, $hierarchical = false) {
        RWBE_Debug_Logger::log('Ensuring attribute exists', [
            'attribute_slug' => $attribute_slug,
            'attribute_label' => $attribute_label
        ]);
        
        if (!function_exists('wc_get_attribute_taxonomies')) {
            RWBE_Debug_Logger::log('WooCommerce functions not available', ['function' => 'wc_get_attribute_taxonomies']);
            return false;
        }
        
        $attribute_name = 'pa_' . $attribute_slug;
        
        // Verificar se o atributo já existe
        $attribute_id = 0;
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        
        foreach ($attribute_taxonomies as $taxonomy) {
            if ($taxonomy->attribute_name === $attribute_slug) {
                $attribute_id = $taxonomy->attribute_id;
                RWBE_Debug_Logger::log('Attribute already exists', [
                    'attribute_slug' => $attribute_slug,
                    'attribute_id' => $attribute_id
                ]);
                return true;
            }
        }
        
        RWBE_Debug_Logger::log('Attribute does not exist, creating it', ['attribute_slug' => $attribute_slug]);
        
        // Criar o atributo
        $args = array(
            'name'         => $attribute_label,
            'slug'         => $attribute_slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false
        );
        
        $result = wc_create_attribute($args);
        
        if (is_wp_error($result)) {
            RWBE_Debug_Logger::log('Error creating attribute', [
                'attribute_slug' => $attribute_slug,
                'error' => $result->get_error_message()
            ]);
            return false;
        }
        
        $attribute_id = $result;
        RWBE_Debug_Logger::log('Attribute created successfully', [
            'attribute_slug' => $attribute_slug,
            'attribute_id' => $attribute_id
        ]);
        
        // Forçar a atualização do cache de atributos do WooCommerce
        delete_transient('wc_attribute_taxonomies');
        
        // Registrar a taxonomia
        $taxonomy_args = array(
            'labels'       => array(
                'name' => $attribute_label,
            ),
            'hierarchical' => $hierarchical,
            'show_ui'      => true,
            'query_var'    => true,
            'rewrite'      => false,
        );
        
        register_taxonomy($attribute_name, array('product'), $taxonomy_args);
        
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Failed to register taxonomy', ['attribute_name' => $attribute_name]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Taxonomy registered successfully', ['attribute_name' => $attribute_name]);
        
        // Limpar o cache de taxonomias de forma mais eficiente
        delete_transient('wc_attribute_taxonomies');
        
        return true;
    }

    /**
     * Efficiently clear product cache
     * 
     * @param int $product_id Product ID
     */
    public static function clear_product_cache($product_id) {
        if (empty($product_id)) {
            return;
        }
        
        // Clean post cache
        clean_post_cache($product_id);
        
        // Clean WooCommerce specific caches
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        
        // Update the product object if needed for critical changes
        $product = wc_get_product($product_id);
        if ($product) {
            $product->save();
        }
    }
}
