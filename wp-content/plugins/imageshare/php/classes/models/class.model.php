<?php

namespace Imageshare\Models;

use Imageshare\Logger;

class Model {
    public static function get_image_sizes() {
        global $_wp_additional_image_sizes;

        $default_image_sizes = get_intermediate_image_sizes();

        foreach ($default_image_sizes as $size) {
            $image_sizes[$size]['width'] = intval(get_option("{$size}_size_w"));
            $image_sizes[$size]['height'] = intval(get_option("{$size}_size_h"));
            $image_sizes[$size]['crop'] = get_option("{$size}_crop") ? get_option("{$size}_crop") : false;
        }

        if (isset($_wp_additional_image_sizes) && count($_wp_additional_image_sizes)) {
            $image_sizes = array_merge($image_sizes, $_wp_additional_image_sizes);
        }

        return $image_sizes;
    }

    public static function force_publish_children($children) {
        foreach ($children as $child) {
            if (!in_array($child->post->post_status, ['draft', 'pending'])) {
                Logger::log("{$child->post->post_type} {$child->id} status is {$child->post->post_status}, skipping");
                continue;
            }

            $child->post->post_status = 'publish';

            if (!$result = wp_update_post($child->post)) {
                Logger::log("Unable to force publish {$child->post->post_type} {$child->id}");
                continue;
            }

            Logger::log("Force published {$child->post->post_type} {$child->id}");
        }
    }

    public static function children_by_status($children) {
        return array_reduce($children, function($carry, $item) {
            $status = $item->post->post_status;
            if ($status === 'publish') {
                $status = 'published';
            }

            if (!array_key_exists($status, $carry)) {
                $carry[$status] = 1;
            } else {
                $carry[$status]++;
            }

            return $carry;
        }, []);
    }

    public static function as_search_term($term, $value) {
        return
            'imageshare' .
            preg_replace('/_{2,}/', '',
            preg_replace('/[^\w]+/', '',
            preg_replace('/\s+/', '', implode('', [$term, $value]
        ))));
    }

    public static function flatten(array $arr) {
        return array_reduce($arr, function ($c, $a) {
            return is_array($a) ? array_merge($c, Model::flatten($a)) : array_merge($c, [$a]);
        }, []);
    }

    public static function get_meta_term_name(string $post_id, string $meta_key, string $taxonomy, bool $reverse = false) {
        $term_id = get_post_meta($post_id, $meta_key, true);

        $term = get_term($term_id, $taxonomy);

        if (is_wp_error($term)) {
            error_log(sprintf('Unable to get term %s with term_id %d in taxonomy %s', $meta_key, $term_id, $taxonomy));
            return '';
        }

        if ($parent_id = $term->parent) {
            $parent_term = get_term($parent_id);
            return join(' - ', $reverse ? [$term->name, $parent_term->name] : [$parent_term->name, $term->name]);
        }

        return $term->name;
    }

    public static function get_hierarchical_terms($taxonomy, $hide_empty) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'orderby' => 'name',
            'hierarchical' => false,
            'hide_empty' => false,
            'count' => true,
            'parent' => 0
        ]);

        $results = [];

        foreach ($terms as $term) {
            if ($term->count || !$hide_empty) {
                $results[$term->term_id] = [$term->name];
            }

            $children = get_terms([
                'taxonomy' => $taxonomy,
                'parent' => $term->term_id,
                'orderby' => 'name',
                'hide_empty' => false,
                'count' => true
            ]);

            foreach ($children as $child) {
                if ($child->count || !$hide_empty) {
                    $results[$child->term_id] = [$child->name, $term->name];
                }
            }
        }

        return $results;
    }

    public static function get_terms($taxonomy, $hide_empty) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'orderby' => 'name',
            'hide_empty' => $hide_empty
        ]);

        return array_reduce($terms, function ($carry, $item) {
            $carry[$item->term_id] = $item->name;
            return $carry;
        }, []);
    }

    public static function get_taxonomy_term_id($taxonomy, $term_name) {
        $term = get_term_by('name', $term_name, $taxonomy);

        if ($term === false) {
            throw new \Exception(sprintf(__('Term %s was not found in taxonomy %s', 'imageshare'), $term_name, $taxonomy));
        }

        return $term->term_id;
    }

    public static function get_taxonomy_term_name($term_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);

        if ($term === null || is_wp_error($term)) {
            return null;
        }

        return $term->name;
    }

    public static function finish_importing($post_id) {
        // remove importing flag
        // this is done so the search indexing hook doesn't cause problems
        delete_post_meta($post_id, 'importing');
    }

    public static function is_importing($post_id) {
        return get_post_meta($post_id, 'importing', false);
    }
}

