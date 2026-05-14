<?php

namespace Wenprise;

class TemplateHelper
{
    public $template_path;
    
    public $default_paths;
    
    public function __construct($template_path = 'wenprise', $default_paths = [])
    {
        $this->template_path = $template_path;
        $this->default_paths = (array)$default_paths;
    }

    /**
     * Given a path, this will convert any of the subpaths into their corresponding tokens.
     *
     * @param string $path        The absolute path to tokenize.
     * @param array  $path_tokens An array keyed with the token, containing paths that should be replaced.
     *
     * @return string The tokenized path.
     * @since 4.3.0
     */
    function tokenize_path($path, $path_tokens)
    {
        if (empty($path) || ! is_string($path)) {
            return $path;
        }

        $path = wp_normalize_path($path);

        // Order most to at least specific so that the token can encompass as much of the path as possible.
        uasort(
            $path_tokens,
            function ($a, $b)
            {
                $a = strlen($a);
                $b = strlen($b);

                if ($a > $b) {
                    return -1;
                }

                if ($b > $a) {
                    return 1;
                }

                return 0;
            }
        );

        foreach ($path_tokens as $token => $token_path) {
            $token_path = wp_normalize_path($token_path);

            if (empty($token_path) || 0 !== strpos($path, $token_path)) {
                continue;
            }

            $path = str_replace($token_path, '{{' . $token . '}}', $path);
        }

        return $path;
    }

    /**
     * Given a tokenized path, this will expand the tokens to their full path.
     *
     * @param string $path        The absolute path to expand.
     * @param array  $path_tokens An array keyed with the token, containing paths that should be expanded.
     *
     * @return string The absolute path.
     * @since 4.3.0
     */
    function untokenize_path($path, $path_tokens)
    {
        foreach ($path_tokens as $token => $token_path) {
            $path = str_replace('{{' . $token . '}}', $token_path, $path);
        }

        return $path;
    }


    /**
     * Fetches an array containing all of the configurable path constants to be used in tokenization.
     *
     * @return array The key is the define and the path is the constant.
     */
    function get_path_define_tokens()
    {
        $defines = [
            'ABSPATH',
            'WP_CONTENT_DIR',
            'WP_PLUGIN_DIR',
            'WPMU_PLUGIN_DIR',
            'PLUGINDIR',
            'WP_THEME_DIR',
        ];

        $path_tokens = [];
        foreach ($defines as $define) {
            if (defined($define) && ! empty(constant($define))) {
                $path_tokens[ $define ] = constant($define);
            }
        }

        return apply_filters('wenprise_get_path_define_tokens', $path_tokens);
    }


    /**
     * Add a template to the template cache.
     *
     * @param string $cache_key Object cache key.
     * @param string $template  Located template.
     *
     * @since 4.3.0
     */
    function set_template_cache($cache_key, $template)
    {
        wp_cache_set($cache_key, $template, 'wenprise');

        $cached_templates = wp_cache_get('cached_templates', 'wenprise');
        if (is_array($cached_templates)) {
            $cached_templates[] = $cache_key;
        } else {
            $cached_templates = [$cache_key];
        }

        wp_cache_set('cached_templates', $cached_templates, 'wenprise');
    }


    /**
     * Clear the template cache.
     *
     * @since 4.3.0
     */
    function clear_template_cache()
    {
        $cached_templates = wp_cache_get('cached_templates', 'wenprise');
        if (is_array($cached_templates)) {
            foreach ($cached_templates as $cache_key) {
                wp_cache_delete($cache_key, 'wenprise');
            }

            wp_cache_delete('cached_templates', 'wenprise');
        }
    }


    /**
     * Locate a template and return the path for inclusion.
     *
     * This is the load order:
     *
     * yourtheme/$template_path/$template_name
     * yourtheme/$template_name
     * $default_path/$template_name
     *
     * @param string $template_name Template name.
     *
     * @return string
     */
    function locate_template($template_name)
    {
        $template_path = $this->template_path;
        $default_paths = $this->default_paths;
        $template      = '';

        // Handle both string and array for template name
        $template_names = (array)$template_name;

        // Create hyphenated versions for product cat/tag
        $cs_names = [];
        foreach ($template_names as $name) {
            if (is_string($name) && (false !== strpos($name, 'product_cat') || false !== strpos($name, 'product_tag'))) {
                $cs_names[] = str_replace('_', '-', $name);
            }
        }

        if ( ! empty($cs_names)) {
            $locate_cs_names = [];
            foreach ($cs_names as $cs_name) {
                $locate_cs_names[] = trailingslashit($template_path) . $cs_name;
                $locate_cs_names[] = $cs_name;
            }

            $template = locate_template($locate_cs_names);
        }

        if (empty($template)) {
            $locate_names = [];
            foreach ($template_names as $name) {
                $locate_names[] = trailingslashit($template_path) . $name;
                $locate_names[] = $name;
            }

            $template = locate_template($locate_names);
        }

        // Get default template/.
        if ( ! $template) {
            if ( ! empty($cs_names)) {
                $template = $this->locate_default_template($cs_names, (array)$default_paths);
            }

            if ( ! $template) {
                $template = $this->locate_default_template($template_names, (array)$default_paths);
            }
        }

        // Return what we found.
        return apply_filters('wenprise_locate_template', $template, $template_name, $template_path);
    }


    /**
     * 定位默认模版
     *
     * @param $template
     * @param $paths
     *
     * @return false|string
     */
    function locate_default_template($template, $paths)
    {
        $templates = (array)$template;

        foreach ($paths as $path) {
            foreach ($templates as $t) {
                $template_path = wp_normalize_path($path . '/' . ltrim($t, '/'));

                if (file_exists($template_path)) {
                    return $template_path;
                }
            }
        }

        return false;
    }


    /**
     * Get other templates (e.g. product attributes) passing attributes and including the file.
     *
     * @param string $template_name Template name.
     * @param array  $args          Arguments. (default: array).
     */
    function get_template($template_name, $args = [])
    {
        $template_path = $this->template_path;
        $default_paths = (array)$this->default_paths;

        $cache_key = 'wprs_tpl_' . md5(serialize([$template_name, $template_path, $default_paths, '1.1']));
        $template  = (string)wp_cache_get($cache_key, 'wenprise');

        if ( ! $template) {
            $template = $this->locate_template($template_name);

            // Don't cache the absolute path so that it can be shared between web servers with different paths.
            $cache_path = $this->tokenize_path($template, $this->get_path_define_tokens());

            $this->set_template_cache($cache_key, $cache_path);
        } else {
            // Make sure that the absolute path to the template is resolved.
            $template = $this->untokenize_path($template, $this->get_path_define_tokens());
        }

        // Allow 3rd party plugin filter template file from their plugin.
        $filter_template = apply_filters('wprs_get_template', $template, $template_name, $args, $template_path, $default_paths);

        if ($filter_template !== $template) {
            if ( ! file_exists($filter_template)) {
                /* translators: %s template */
                _doing_it_wrong(__FUNCTION__, sprintf(__('%s does not exist.', 'wenprise'), '<code>' . $filter_template . '</code>'), '2.1');

                return;
            }
            $template = $filter_template;
        }

        $action_args = [
            'template_name' => $template_name,
            'template_path' => $template_path,
            'located'       => $template,
            'args'          => $args,
        ];

        if ( ! empty($args) && is_array($args)) {
            if (isset($args[ 'action_args' ])) {
                _doing_it_wrong(
                    __FUNCTION__,
                    __('action_args should not be overwritten when calling wprs_get_template.', 'wenprise'),
                    '3.6.0'
                );
                unset($args[ 'action_args' ]);
            }
            extract($args); // @codingStandardsIgnoreLine
        }

        do_action('wenprise_before_template_part', $action_args[ 'template_name' ], $action_args[ 'template_path' ], $action_args[ 'located' ], $action_args[ 'args' ]);

        if ( ! empty($action_args[ 'located' ]) && file_exists($action_args[ 'located' ])) {
            include $action_args[ 'located' ];
        }

        do_action('wenprise_after_template_part', $action_args[ 'template_name' ], $action_args[ 'template_path' ], $action_args[ 'located' ], $action_args[ 'args' ]);
    }
}
