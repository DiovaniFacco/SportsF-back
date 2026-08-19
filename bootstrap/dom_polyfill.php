<?php

if (!class_exists('DOMDocument')) {
    if (!defined('LIBXML_NOERROR')) define('LIBXML_NOERROR', 0);
    if (!defined('LIBXML_COMPACT')) define('LIBXML_COMPACT', 0);
    if (!defined('LIBXML_HTML_NODEFDTD')) define('LIBXML_HTML_NODEFDTD', 0);
    if (!defined('LIBXML_NOBLANKS')) define('LIBXML_NOBLANKS', 0);
    if (!defined('LIBXML_NOXMLDECL')) define('LIBXML_NOXMLDECL', 0);

    class DOMNode {
        public string $nodeName = 'div';
        public string $nodeValue = '';
        public array $childNodes = [];
        public function getChildNodes() { return []; }
        public function isName($n) { return $n === $this->nodeName; }
        public function isText() { return false; }
        public function isComment() { return false; }
        public function isFirstChild() { return true; }
        public function getClassAttribute() { return ''; }
        public function getName() { return $this->nodeName; }
        public function getAttribute($a) { return ''; }
        public function __toString() { return $this->nodeValue; }
        public function __get($name) { return $name === 'childNodes' ? [] : null; }
    }

    class DOMNodeList {
        public function item($index) {
            $node = new DOMNode();
            $node->nodeName = 'div';
            return $node;
        }
    }

    class DOMDocument {
        public function loadHTML($html, $flags = 0) { return true; }
        public function getElementsByTagName($name) { return new DOMNodeList(); }
    }
}
