<?php

namespace FluentSnippets\App\Model;

use FluentSnippets\App\Helpers\Helper;

class Snippet
{

    private $ars = [];

    public function __construct($args = [])
    {
        $this->args = $args;
    }

    public function get($args = [])
    {
        if ($args) {
            $this->args = $args;
        }

        $args = $this->args;

        $snippetDir = Helper::getStorageDir();
        // get the file paths and store them in an array
        $files = glob($snippetDir . '/*.php');

        if (isset($args['order']) && $args['order'] == 'new_first') {
            $files = array_reverse($files);
        }

        $formattedFiles = [];
        foreach ($files as $file) {
            $fileContent = file_get_contents($file);
            [$docBlockArray, $code] = $this->parseBlock($fileContent);

            if (!$docBlockArray) {
                continue;
            }

            if (!empty($args['status'])) {
                if ($args['status'] !== $docBlockArray['status']) {
                    continue;
                }
            }

            $formattedFiles[] = [
                'meta'   => $docBlockArray,
                'code'   => $code,
                'file'   => $file,
                'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
            ];
        }
        return $formattedFiles;
    }

    public function paginate($perPage = null, $page = null)
    {
        if ($perPage === null) {
            if (isset($_GET['per_page'])) {
                $perPage = $_GET['per_page'];
            } else {
                $perPage = 10;
            }
        }

        if ($page === null) {
            if (isset($_GET['page'])) {
                $page = $_GET['page'];
            } else {
                $page = 1;
            }
        }
        $offset = ($page - 1) * $perPage;

        $snippets = $this->get([
            'order' => 'new_first'
        ]);

        $total = count($snippets);
        $snippets = array_slice($snippets, $offset, $perPage);

        return [
            'data'         => $snippets,
            'total'        => $total,
            'per_page'     => (int)$perPage,
            'current_page' => (int)$page,
            'last_page'    => (int)ceil($total / $perPage)
        ];
    }

    public function findByFileName($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        if (!file_exists($file) && $fileName === 'index.php') {
            return new \WP_Error('file_not_found', 'File not found');
        }

        $fileContent = file_get_contents($snippetDir . '/' . $fileName);
        [$docBlockArray, $code] = $this->parseBlock($fileContent);

        return [
            'meta'   => $docBlockArray,
            'code'   => $code,
            'file'   => $file,
            'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
        ];
    }

    public function updateSnippet($fileName, $code, $metaData)
    {
        $metaData['updated_at'] = date('Y-m-d H:i:s');

        $file = Helper::getStorageDir() . '/' . $fileName;

        if (!file_exists($file)) {
            return new \WP_Error('file_not_found', 'File not found');
        }

        $docBlockString = $this->parseInputMeta($metaData, true);

        $fullCode = $docBlockString . $code;

        file_put_contents($file, $fullCode);

        return $fileName;
    }

    public function createSnippet($code, $metaData)
    {
        $storageDir = Helper::getStorageDir();
        $fileCount = count(glob($storageDir . '/*.php'));

        if (!$fileCount) {
            Helper::cacheSnippetIndex();
            $fileCount = 1;
        }

        $fileName = $fileCount . '-' . sanitize_title($metaData['name'], 'snippet', 'display') . '.php';

        $fileName = sanitize_file_name($fileName);

        $file = $storageDir . '/' . $fileName;

        if (file_exists($file)) {
            return new \WP_Error('file_exists', 'Please try a different name');
        }

        $docBlockString = $this->parseInputMeta($metaData, true);

        $fullCode = $docBlockString . $code;

        file_put_contents($file, $fullCode);

        return $fileName;
    }

    public function deleteSnippet($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        if (!file_exists($file) && $fileName === 'index.php') {
            return new \WP_Error('file_not_found', 'File not found');
        }

        unlink($file);

        return true;
    }

    private function parseBlock($fileContent)
    {
        // get content from // <Internal Doc Start> to // <Internal Doc End>
        $fileContent = explode('// <Internal Doc Start>', $fileContent);

        if (count($fileContent) < 2) {
            return [null, null];
        }

        $fileContent = explode('// <Internal Doc End> ?>' . PHP_EOL, $fileContent[1]);
        $docBlock = $fileContent[0];
        $code = $fileContent[1];

        $docBlock = explode('*', $docBlock);
        // Explode by : and get the key and value
        $docBlockArray = [
            'name'        => '',
            'status'      => '',
            'tags'        => '',
            'description' => '',
            'type'        => '',
            'run_at'      => ''
        ];

        foreach ($docBlock as $key => $value) {
            $value = trim($value);
            $arr = explode(':', $value);
            if (count($arr) < 2) {
                continue;
            }

            // get the first item from the array and remove it from $arr
            $key = array_shift($arr);
            $key = trim(str_replace('@', '', $key));
            if (!$key) {
                continue;
            }
            $docBlockArray[$key] = trim(implode(':', $arr));
        }

        return [$docBlockArray, $code];
    }

    private function parseInputMeta($metaData, $convertString = false)
    {
        $metaDefaults = [
            'type'       => 'PHP',
            'status'     => 'draft',
            'created_by' => get_current_user_id(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_valid'   => 1,
            'tags'       => '',
            'updated_by' => get_current_user_id(),
            'name'       => 'Snippet Created @ ' . current_time('mysql'),
            'priority'   => 10
        ];

        $metaData = wp_parse_args($metaData, $metaDefaults);

        if (!is_numeric($metaData['priority']) || $metaData['priority'] < 1) {
            $metaData['priority'] = 10;
        }

        if (!$convertString) {
            return $metaData;
        }

        $docBlockString = '<?php' . PHP_EOL . '// <Internal Doc Start>' . PHP_EOL . '/*' . PHP_EOL . '*';

        foreach ($metaData as $key => $value) {
            $docBlockString .= PHP_EOL . '* @' . $key . ': ' . $value;
        }

        $docBlockString .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL . '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>' . PHP_EOL;

        return $docBlockString;
    }
}
