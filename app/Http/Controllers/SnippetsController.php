<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SnippetsController
{
    public static function getSnippets(\WP_REST_Request $request)
    {
        $snippetModel = new Snippet([
            'search' => sanitize_text_field($request->get_param('search')),
        ]);
        $snippets = $snippetModel->paginate();

        foreach ($snippets['data'] as &$snippet) {
            $snippet['file_name'] = basename($snippet['file']);
        }

        return [
            'snippets' => $snippets
        ];
    }

    public static function findSnippet(\WP_REST_Request $request)
    {
        $snippetName = sanitize_file_name($request->get_param('snippet_name'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($snippetName);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        $snippet['file_name'] = basename($snippet['file']);

        if ($snippet['meta']['type'] == 'PHP') {
            // Remove Beginning php tag
            $snippet['code'] = preg_replace('/^<\?php/', '', $snippet['code']);
            // remove new line at the very first
            $snippet['code'] = ltrim($snippet['code'], PHP_EOL);
        }

        return [
            'snippet' => $snippet
        ];
    }


    public static function createSnippet(\WP_REST_Request $request)
    {
        $meta = $request->get_param('meta');
        $code = $request->get_param('code');

        $metaValidated = self::validateMeta($meta);

        if(is_wp_error($metaValidated)) {
            return $metaValidated;
        }

        $meta['status'] = 'draft';

        // Check if the php snippet $code is valid or not by validating it

        if ($meta['type'] == 'PHP') {
            // Check if the code starts with <?php
            if (preg_match('/^<\?php/', $code)) {
                return new \WP_Error('invalid_code', 'Please remove <?php from the beginning of the code');
            }
            $code = rtrim($code, '?>');
            $code = '<?php' . PHP_EOL . $code;
        }

        // Validate the code
        $validated = Helper::validateCode($meta['type'], $code);

        if (is_wp_error($validated)) {
            return $validated;
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->createSnippet($code, $meta);
        do_action('fluent_snippets/snippet_created', $snippet);

        return [
            'snippet' => $snippet,
            'message' => 'Snippet created successfully'
        ];
    }

    public static function updateSnippet(\WP_REST_Request $request)
    {
        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));
        $meta = $request->get_param('meta');
        $code = $request->get_param('code');

        $metaValidated = self::validateMeta($meta);

        if(is_wp_error($metaValidated)) {
            return $metaValidated;
        }

        // Check if the php snippet $code is valid or not by validating it

        if ($meta['type'] == 'PHP') {
            $code = rtrim($code, '?>');
            $code = '<?php' . PHP_EOL . $code;
        }

        // Validate the code
        $validated = Helper::validateCode($meta['type'], $code);

        if (is_wp_error($validated)) {
            return $validated;
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->updateSnippet($fileName, $code, $meta);

        do_action('fluent_snippets/snippet_updated', $snippet);

        return [
            'snippet' => $snippet,
            'message' => 'Snippet updated successfully'
        ];
    }

    public static function updateSnippetStatus(\WP_REST_Request $request)
    {
        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));
        $status = sanitize_text_field($request->get_param('status'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($fileName);

        if(is_wp_error($snippet)) {
            return $snippet;
        }

        if($status != 'published') {
            $status = 'draft';
        }

        $snippet['meta']['status'] = $status;

        $snippetName = $snippetModel->updateSnippet($fileName, $snippet['code'], $snippet['meta']);

        do_action('fluent_snippets/snippet_status_updated', $snippetName);
        do_action('fluent_snippets/snippet_updated', $snippetName);

        return [
            'snippet' => $snippet,
            'message' => 'Snippet status updated successfully'
        ];
    }

    public static function deleteSnippet(\WP_REST_Request $request)
    {
        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($fileName);

        if(is_wp_error($snippet)) {
            return $snippet;
        }

        $snippetModel->deleteSnippet($fileName);

        do_action('fluent_snippets/snippet_deleted', $fileName);

        return [
            'message' => __('Snippet has been deleted successfully', 'fluent-snippets')
        ];
    }

    private static function validateMeta($meta)
    {
        $required = ['name', 'status', 'type'];

        foreach ($required as $key) {
            if (empty($meta[$key])) {
                return new \WP_Error('invalid_meta', sprintf('%s is required', $key));
            }
        }

        return true;
    }
}
