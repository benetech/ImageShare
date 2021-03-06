<?php

namespace Imageshare\Controllers;

use Imageshare\Logger;
use Imageshare\Models\Model as Model;
use Imageshare\Models\Resource as ResourceModel;
use Imageshare\Models\ResourceFile as ResourceFileModel;
use Imageshare\Models\ResourceCollection as ResourceCollectionModel;

class Search {

    public function __construct() {
        add_filter('wpfts_index_post', [$this, 'index_post'], 3, 2);
    }

    public function index_post($index, $post) {
        Logger::log("Indexing post {$post->ID}");

        if ($post->post_status !== 'publish') {
            Logger::log("Post status is {$post->post_status}, skipping");
            return $index;
        }

        switch ($post->post_type) {
            case (ResourceModel::type):
                $type = ResourceModel::type;
                $resource = ResourceModel::from_post($post);
                if ($resource->is_importing) {
                    Logger::log("Post {$post->ID} is being imported, skipping");
                    return $index;
                }

                $index['post_title'] = $resource->title;
                $index['post_content'] = '';
                $index[$type . '_data'] = implode(' ', array_unique($resource->get_index_data()));

                // index data distilled from published child resource files
                $index[$type . '_child_content'] = implode(' ', $resource->get_index_data('files'));

                // subject and keyword-specific relevance clusters
                $index[$type . '_subject'] = implode(' ', $resource->get_index_data('subject'));
                $index[$type . '_type'] = implode(' ', $resource->get_index_data('type'));
                $index[$type . '_accommodation'] = implode(' ', $resource->get_index_data('accommodation'));

                break;

            case (ResourceFileModel::type):
                $resource_file = ResourceFileModel::from_post($post);
                if ($resource_file->is_importing) {
                    Logger::log("Post {$post->ID} is being imported, skipping");
                    return $index;
                }

                $index['post_content'] = '';
                $index[ResourceFileModel::type . '_data'] = implode(' ', array_unique($resource_file->get_index_data()));

                break;

             case (ResourceCollectionModel::type):
                $resource_collection = ResourceCollectionModel::from_post($post);
                $type = ResourceCollectionModel::type;
                $index['post_content'] = '';
                $index[$type . '_data'] = implode(' ', array_unique($resource_collection->get_index_data()));

                // index data distilled from published child resources
                $index[$type . '_child_content'] = implode(' ', $resource_collection->get_index_data('resources'));

                // subject and keyword-specific relevance clusters
                $index[$type . '_subject'] = implode(' ', $resource_collection->get_index_data('subject'));
                $index[$type . '_type'] = implode(' ', $resource_collection->get_index_data('type'));
                $index[$type . '_accommodation'] = implode(' ', $resource_collection->get_index_data('accommodation'));

                break;

            default:
                $index['post_title'] = $post->post_title;
                $index['post_content'] = strip_tags($post->post_content);
                break;
        }

        Logger::log("Post {$post->ID} ({$post->post_type}) added to index");

        return $index;
    }

    public static function get_available_sources() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $results = $wpdb->get_col("select distinct meta_value as source from {$prefix}postmeta where meta_key=\"source\" and length(meta_value) > 0 order by source ASC;");
        return $results;
    }

    public static function get_available_terms() {
        return [
            'subjects'       => ResourceModel::available_subjects($hide_empty = true),
            'accommodations' => ResourceFileModel::available_accessibility_accommodations($hide_empty = true),
            'types'          => ResourceFileModel::available_types($hide_empty = true)
        ];
    }

    public function get_terms($args) {
        $results = [
            'subject' => [],
            'type' => [],
            'acc' => []
        ];

        foreach (['subject', 'type', 'acc'] as $filter) {
            if (isset($args[$filter]) && self::is_nonempty_array($args[$filter])) {
                foreach ($args[$filter] as $term_id) {
                    $term = get_term($term_id);
                    if (!is_wp_error($term) && $term !== null) {
                        array_push($results[$filter], $term);
                    }
                }
            }
        }

        if (isset($args['source']) && strlen($args['source'])) {
            $results['source'] = $args['source'];
        }

        return $results;
    }

    public function get_paging($args, $page = 1, $size = 20, $amount = 0, $total = 0) {
        $valid_size_steps = [2, 20, 50, 100];
        $default_size = 20;
        $default_page = 1;

        if (!is_numeric($page)) {
            $page = $default_page;
        }

        if (!in_array($size, $valid_size_steps)) {
            $size = $default_size;
        }

        $start = ($size * ($page -1) + 1) ?? 1;
        $stop = ($start + $size - 1) < $total ? $start + $size -1 : $total;

        $first_page = $page > 1 ? 1 : null;
        $prev_page = $first_page && $page-1 >= $first_page && $page-1 < $page ? $page-1 : null;
        $last_page = ceil($total / $size) > $page ? ceil($total / $size) : null;
        $next_page = $last_page && $page+1 < $last_page ? $page+1 : null;

        return [
            'args' => $args,

            'size'  => $size,
            'page'  => $page,
            'start' => $start,
            'stop'  => $stop,
            'total' => $total,

            'first_page' => $first_page,
            'prev_page' => $prev_page,
            'next_page' => $next_page,
            'last_page' => $last_page
        ];
    }

    private function _query_resources($results, $args, $query, $terms) {
        $page = $args['rp'] ?? null;
        $size = $args['rs'] ?? null;

        $paging = self::get_paging($args, $page, $size);

        [$resources, $total_resources] = self::post_type_query(
            ResourceModel::type, $query, $terms, $paging
        );

        $resources = array_map(function ($p) {
            return ResourceModel::from_post($p);
        }, $resources);


        $results['resources'] = [
            'posts' => $resources,
            'total' => $total_resources,
            'paging' => self::get_paging($args, $page, $size, count($resources), $total_resources)
        ];

        $results['total_count'] += count($resources);

        return $results;
    }

    private function _query_collections($results, $args, $query, $terms) {
        $page = $args['cp'] ?? null;
        $size = $args['cs'] ?? null;

        $paging = self::get_paging($args, $page, $size);

        [$collections, $total_collections] = self::post_type_query(
            ResourceCollectionModel::type, $query, $terms, $paging
        );

        $collections = array_map(function ($p) {
            return ResourceCollectionModel::from_post($p);
        }, $collections);

        $results['collections'] = [
            'posts' => $collections,
            'total' => $total_collections,
            'paging' => self::get_paging($args, $page, $size, count($collections), $total_collections)
        ];

        $results['total_count'] += count($collections);

        return $results;
    }

    public function query($args) {
        $query = trim($args['query']);
        $terms = self::get_terms($args);

        $results = [
            'total_count' => 0
        ];

        if (isset($args['_single_type'])) {
            $type = $args['_single_type'];
            if ($type === ResourceModel::type) {
                $results = $this->_query_resources($results, $args, $query, $terms);
            } else if ($type === ResourceCollectionModel::type) {
                $results = $this->_query_collections($results, $args, $query, $terms);
            } else {
                $results = $this->_query_resources($results, $args, $query, $terms);
                $results = $this->_query_collections($results, $args, $query, $terms);
            }
        } else {
            $results = $this->_query_resources($results, $args, $query, $terms);
            $results = $this->_query_collections($results, $args, $query, $terms);
        }

        $filters = array_merge(['query' => $query], $terms);

        $results['has_filters'] =
            count($filters['subject']) ||
            count($filters['type'])    ||
            count($filters['acc'])     ||
            isset($filters['source'])
        ;

        $results['search_filters'] = $filters;

        return $results;
    }

    public static function is_nonempty_array($var) {
        return is_array($var) && count($var) > 0;
    }

    public function post_type_query($type, $query, $terms, $paging) {
        if (strlen($query)) {
            $cluster_weights = [
                'post_title' => 0.7,
                ($type . '_child_content') => 0.9,
                ($type . '_data') => 0.9
            ];
            $query = [$query];
        } else {
            $cluster_weights = [];
            $query = [];
        }

        $search_term_prefix = [
            'subject' => 'subject',
            'type' => 'type',
            'acc' => 'accommodation'
        ];

        foreach (['subject', 'type', 'acc'] as $filter) {
            if (count($terms[$filter])) {
                $cluster_weights[$type . '_' . $filter] = 0.9;
            }

            foreach ($terms[$filter] as $term) {
                $prefix = $search_term_prefix[$filter];
                array_push($query, Model::as_search_term($prefix, $term->name));
            }
        }

        $meta_query = [];

        if (isset($terms['source'])) {
            array_push($meta_query, [
                'key' => 'source',
                'value' => $terms['source'],
                'compare' => '='
            ]);
        }

        $results = [];

        $wpq = new \WP_Query([
            'post_type' => $type,
            'post_status' => 'publish',
            'fields' => '*',
            'wpfts_disable' => 0,
            'wpfts_nocache' => 1,
            'cluster_weights' => $cluster_weights,
            's' => implode(' ', $query),
            'posts_per_page' => $paging['size'],
            'paged' => $paging['page'],
            'meta_query' => $meta_query
        ]);

        while ($wpq->have_posts()) {
            $wpq->the_post();
            $post = $wpq->post;
            array_push($results, $post);
        }

        return [$results, $wpq->found_posts];
    }

    public function get_published_resource_count() {
        return wp_count_posts(ResourceModel::type)->publish;
    }
}
