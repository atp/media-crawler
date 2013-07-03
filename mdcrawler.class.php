<?php

require_once("lib/PHPCrawl/PHPCrawler.class.php");

class Resource {
    
    var $namespace;
    var $localname;
    
    function __construct($namespace, $localname) {
        $this->namespace = $namespace;
        $this->localname = $localname;
    }
    
    function getUrl() {
        return $this->namespace.''.$this->localname;
    }
}

class Metadata {
    
    var $id;
    var $resource;
    var $properties = array();
    
    public function __construct($resource) {
        $this->resource = $resource;
    }

    public function addProperty($name, $value, $key = NULL) {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
        }
        if (isset($key)) {
            $this->properties[$name][$key] = $value;
        } else {
            $this->properties[$name][] = $value;
        }
    }

    public function setProperties($name, $values) {
        $this->properties[$name] = $values;
    }
    
    public static function mergeMetadatas($metadatas, $ids) {
        sort($ids);
        $metadata = $metadatas[array_shift($ids)];
        foreach ($ids as $id) {
            $joinMetadata = $metadatas[$id];
            foreach ($joinMetadata->properties as $key=>$values) {
                foreach ($values as $value) {
                    if (!in_array($value, $metadata->properties[$key])) {
                        array_push($metadata->properties[$key], $value);
                    }
                }
            }
        }
        return $metadata;
    }
    
}

/**
 * Function that obtain the diference between two string $string1, $string2
 * Example:
 * $diffs = getStringDiffs('prefix_diff1_sufix', 'prefixdiff2sufix');
 * return array('_diff1_', 'diff2');
 */
function getStringDiffs($string1, $string2) {
    $len1 = mb_strlen($string1);
    $len2 = mb_strlen($string2);
    //-- remove common prefix
    $idx = 0;
    do {
        if (mb_substr($string1, $idx, 1) != mb_substr($string2, $idx, 1)) {
            break;
        } else {
            $idx++; $len1--; $len2--;
        }
    } while ($len1 > 0 && $len2 > 0);
    if ($idx > 0) {
        $string1 = mb_substr($string1, $idx);
        $string2 = mb_substr($string2, $idx);
    }
    //-- remove common suffix
    $idx = 0;
    do {
        if (mb_substr($string1, $len1 - 1, 1) != mb_substr($string2, $len2 - 1, 1)) {
            break;
        } else {
            $idx++; $len1--; $len2--;
        }
    } while ($len1 > 0 && $len2 > 0);
    if ($idx > 0) {
        $string1 = mb_substr($string1, 0, $len1);
        $string2 = mb_substr($string2, 0, $len2);
    }
    return array($string1, $string2); 
}

class MDCrawler extends PHPCrawler {
     
    public static $HTML_MEDIA_TYPES = array('text/html', 'application/xhtml+xml',
                                            'application/x-www-form-urlencoded');
     
    public static $IMAGE_MEDIA_TYPES = array('image/gif', 'image/png',
                                             'image/jpeg', 'image/pjpeg');
    
    /**
     * return id of metadata and last modified object
     */
    var $modifieds = array();
    var $metadatas = array();
    var $relations = array(); 
    var $thumbnails = array();
    var $xpathresults = array();

    private $to_update_xpaths = array();
    private $to_update_modifieds = array();

    var $metadataTemplate;
    var $thumbBaseDir;
    var $thumbnailSizes;
    
    // (TODO) multiple versions use only resource info
    // this info must be expanded to use properties
    var $mergeVersions = false;
    var $multipleVersions;
    var $contentTypes = array();
    var $excludeFiles = array();
    
    public function __construct() {
        parent::__construct();
        foreach (self::$HTML_MEDIA_TYPES as $type) {
            $this->addContentTypeReceiveRule('#'.$type.'#');
        }
    }
    
    public function goMultiProcessed($process_count = 3, $multiprocess_mode = 1) {
        parent::goMultiProcessed($process_count, $multiprocess_mode);
        $this->updateMetadatasWithXPaths();
        $this->createMetadataRelations();
        if ($this->mergeVersions) {
            $this->mergeMetadatas();
        }
    }
    
    public function go() {
        parent::go();
        $this->updateMetadatasWithXPaths();
        $this->createMetadataRelations();
        if ($this->mergeVersions) {
            $this->mergeMetadatas();
        }
    }
    
    function setMergeVersions($mergeVersions) {
        $this->mergeVersions = $mergeVersions;
    }
    
    function setMetadataTemplate($metadataTemplate) {
        $this->metadataTemplate = $metadataTemplate;
    }
    
    function setMultipleVersions($multipleVersions) {
        $this->multipleVersions = $multipleVersions;
    }

    function setThumbBaseDir($basedir) {
        $this->thumbBaseDir = $basedir;
    }

    function setThumbnailSizes($thumbnailSizes) {
        $this->thumbnailSizes = $thumbnailSizes;
    }
    
    function getMetadatas() {
        return $this->metadatas;
    }
    
    function getRelations() {
        return $this->relations;
    }

    function getLastModified($metadataid){
        return $this->modifieds[$metadataid];
    }
    
    function getModifieds() {
        return $this->modifieds;
    }
    
    function addContentType($type) { 
        $this->contentTypes[] = $type;
        if (in_array($type, self::$IMAGE_MEDIA_TYPES)) {
            $this->addContentTypeReceiveRule('#'.$type.'#');
        }
    }
    
    function addExcludeFile($file) { 
        $this->excludeFiles[] = $file;
    }
    
    function getThumbnails() {
        return $this->thumbnails;
    }
   
    function searchVersionOf($metadata, $insensitive=true) {
        $result = array();
        foreach ($this->metadatas as $id=>$searchMetadata) {
            if ($metadata->id == $id) { continue; }
            $found = false;
            foreach ($this->multipleVersions as $property=>$diffs) {
                $values = array();
                $searchValues = array();
                if ($property == '#file') {
                    $values = array(preg_replace('#\.[^.]+$#', '', $metadata->resource->localname));
                    $searchValues = array(preg_replace('#\.[^.]+$#', '', $searchMetadata->resource->localname));
                } else if (isset($searchMetadata->properties[$property])) {
                    $values = $metadata->properties[$property];
                    $searchValues = $searchMetadata->properties[$property];
                }
                // compares foreach values 
                foreach ($values as $value) {
                    foreach ($searchValues as $searchValue) {
                        if ($insensitive) {
                            $value = strtolower($value);
                            $searchValue = strtolower($searchValue);
                        }
                        $foundDiffs = getStringDiffs($value, $searchValue);
                        // verify if found differences are empty or exist in diffs
                        if (($foundDiffs[0] == '' || in_array($foundDiffs[0], $diffs)) &&
                            ($foundDiffs[1] == '' || in_array($foundDiffs[1], $diffs))) {
                            $found = true;
                        }
                        if ($found) break;
                    }
                    if ($found) break;
                }
                if ($found) break;
            }
            if ($found) { $result[$searchMetadata->id] = $searchMetadata; }
        }
        return $result;
    }
    
    private function preprocessingInfo(&$info, &$namespace, &$file) {
        $info->content_length = PHPCrawlerUtils::getHeaderValue($info->header, "content-length");
        $info->last_modified = PHPCrawlerUtils::getHeaderValue($info->header, "last-modified");
        $source_url = $info->url;
        if (substr($source_url, -1, 1) === '/') {
            $source_url = substr($source_url, 0, strlen($source_url) - 1);
        }
        $url_parts = PHPCrawlerUtils::splitURL($source_url);
        $file = $url_parts['file'];
        unset($url_parts['file']);
        $namespace = PHPCrawlerUtils::buildURLFromParts($url_parts, true);
    }
    
    public function handleHeaderInfo($info) {
        $source_url = $info->source_url;
        if (substr($source_url, -1, 1) === '/') {
            $source_url = substr($source_url, 0, strlen($source_url) - 1);
        }
        $file = PHPCrawlerUtils::splitURL($source_url);
        if (in_array($file, $this->excludeFiles)) {
            return -1;
        }
        // update last modifieds
        $last_modified = PHPCrawlerUtils::getHeaderValue($info->header_raw, "last-modified");
        if (isset($last_modified)) {
            $datetime = DateTime::createFromFormat(DateTime::RFC2822, trim($last_modified));
            $this->modifieds[$source_url] = $datetime->getTimestamp();
        }
        return 1;
    }
    
    private function generateThumbnail($data, $output_filename, $size=150) {
        require_once('lib/PHPThumb/phpthumb.class.php');
        $phpThumb = new phpThumb();
        $phpThumb->config_temp_directory = (dirname(__FILE__).'/tmp');
        $phpThumb->setSourceData($data);
        $phpThumb->setParameter('w', $size);
        // generate & output thumbnail
        if ($phpThumb->GenerateThumbnail()) {
            if ($phpThumb->RenderToFile($output_filename)) {
        	    //echo 'Successfully rendered to "'.$output_filename.'"';
            } else { // do something with debug/error messages
                echo 'Failed:<pre>'.implode("\n\n", $phpThumb->debugmessages).'</pre>';
	        }
        } else { // do something with debug/error messages
            echo 'Failed:<pre>'.$phpThumb->fatalerror."\n\n".implode("\n\n", $phpThumb->debugmessages).'</pre>';
        }
    }
    
    public function handleDocumentInfo($info) {
        $this->preprocessingInfo($info, $namespace, $file);
        
        // build xquery using xpath and modifieds
        if ($info->received && in_array($info->content_type, self::$HTML_MEDIA_TYPES)) {
            $html = new DOMDocument();
            $html->loadHTML($info->content);
            $xml = new DOMXPath($html);
            foreach ($this->metadataTemplate as $name=>$value) {
                if (substr($value, 0, 1) === '[' && substr($value, -1, 1) === ']') {
                    $xml_result = $xml->query(substr($value, 1, (strlen($value) - 2)));
                    foreach ($xml_result as $key=>$node) {
                        if (!isset($this->xpathresults[$info->url])) {
                            $this->xpathresults[$info->url] = array();
                        }
                        if (!isset($this->xpathresults[$info->url][$name])) {
                            $this->xpathresults[$info->url][$name] = array();
                        }
                        $this->xpathresults[$info->url][$name][] = $node->nodeValue;
                    }
                }
            }
            // update modifieds
            if (isset($info->last_modified)) {
                $datetime = DateTime::createFromFormat(DateTime::RFC2822, trim($info->last_modified));
                $this->modifieds[$info->url] = $datetime->getTimestamp();
            }
        }
        
        // build tumbnail
        if ($info->received && in_array($info->content_type, $this->contentTypes) &&
            in_array($info->content_type, self::$IMAGE_MEDIA_TYPES)) {
            if (isset($this->thumbnailSizes)) {
                foreach ($this->thumbnailSizes as $size) {
                    $output_filename = $this->thumbBaseDir.'/'.$size;
                    if (!file_exists($output_filename)) {
                        mkdir($output_filename, 0777, true);
                    }
                    $output_filename .= '/'.$info->file;
                    $this->generateThumbnail($info->content, $output_filename, $size);
                    if (!isset($this->thumbnails[$info->url])) {
                        $this->thumbnails[$info->url] = array();
                    }
                    $this->thumbnails[$info->url][$size] = $output_filename;
                }
            } else {
                $output_filename = $this->thumbBaseDir;
                $output_filename .= '/'.$info->file;
                $this->generateThumbnail($info->content, $output_filename);
                $this->thumbnails[$info->url] = $output_filename;
            }
        }
        
        // add to metadatas
        if ($this->starting_url != $info->url && $info->http_status_code == 200 &&
            in_array($info->content_type, $this->contentTypes) && !in_array($file, $this->excludeFiles)) {
            
            $metadata = new Metadata(new Resource($namespace, $file));
            $metadata->id = $info->url;
            foreach ($this->metadataTemplate as $name=>$value) {
                if (substr($value, 0, 1) === '[' && substr($value, -1, 1) === ']') {
                    $url = $info->url;
                    if (!in_array($info->content_type, self::$HTML_MEDIA_TYPES)) {
                        $url = $info->referer_url;
                    }
                    if (isset($this->xpathresults[$url]) && isset($this->xpathresults[$url][$name])) {
                        $values = $this->xpathresults[$url][$name];
                        $metadata->setProperties($name, $values);
                    } else {
                        if (!array_key_exists($metadata->id, $this->to_update_xpaths)) {
                            $this->to_update_xpaths[$metadata->id] = array();
                        }
                        $this->to_update_xpaths[$metadata->id][$name] = $url;
                    }
                } else {
                    $pos = strpos($value, '#');
                    if ($value == '#file') {
                        $value = $file;
                    } else if ($pos !== false && $pos === 0) {
                        $prop = substr($value, 1);
                        if (property_exists($info, $prop)) {
                            $value = $info->{$prop};
                        }
                    } else {
                        $value = array_map('trim', explode(',', $value));
                    }
                    if (is_array($value)) {
                        foreach ($value as $v) { $metadata->addProperty($name, $v); }
                    } else {
                        $metadata->addProperty($name, $value);
                    }
                }
            }
            
            //-- set metadata and modifieds
            $this->metadatas[$metadata->id] = $metadata;
            $url = !in_array($info->content_type, self::$HTML_MEDIA_TYPES) ? $info->referer_url : $info->url;
            if (isset($this->modifieds[$url])) {
                $this->modifieds[$metadata->id] = $this->modifieds[$url];
            } else {
                $this->to_update_modifieds[$metadata->id] = $url;
            }
        }
    }
    
    public function updateMetadatasWithXPaths() {
        foreach ($this->to_update_xpaths as $id=>$property_url) {
            $metadata = $this->metadatas[$id];
            foreach ($property_url as $name=>$url) {
                if (isset($this->xpathresults[$url]) && isset($this->xpathresults[$url][$name])) {
                    $values = $this->xpathresults[$url][$name];
                    $metadata->setProperties($name, $values);
                }
            }
            $this->metadatas[$id] = $metadata;
        }
    }
    
    public function updateModifieds() {
        foreach ($this->to_update_modifieds as $id=>$url) {
            $this->modifieds[$id] = $this->modifieds[$url];
        }
    }
    
    private function createMetadataRelations() {
        $inserted = array();
        foreach ($this->metadatas as $id=>$metadata) {
            if (!in_array($id, $inserted)) {
                $foundMetadataKeys = array_keys($this->searchVersionOf($metadata));
                $foundMetadataKeys[] = $id;
                array_push($this->relations, $foundMetadataKeys);
                foreach ($foundMetadataKeys as $pid) {
                    array_push($inserted, $pid);
                }
            }
        }
    }
    
    private function mergeMetadatas() {
        $metadatas = array();
        foreach ($this->relations as $partition) {
            $metadata = Metadata::mergeMetadatas($this->metadatas, $partition);
            $metadatas[$metadata->id] = $metadata;
        }
        $this->metadatas = $metadatas;
    }
    
}

?>
