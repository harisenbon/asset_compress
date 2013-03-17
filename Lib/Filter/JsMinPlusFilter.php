<?php
App::uses('AssetFilter', 'AssetCompress.Lib');

/**
 * JsMin filter.
 *
 * Allows you to filter Javascript files through JsMin. You need to put JsMin in your application's
 * vendors directories. You can get it from http://github.com/rgrove/jsmin-php/
 *
 * @package asset_compress
 */
class JsMinPlusFilter extends AssetFilter {

/**
 * Where JSMin can be found.
 *
 * @var array
 */
	protected $_settings = array(
		'path' => 'jsminplus/JSMinPlus.php'
	);

/**
 * Apply JsMin to $content.
 *
 * @param string $filename
 * @param string $content Content to filter.
 * @return string
 */
	public function output($filename, $content) {
		$result = App::import('Vendor', 'JSMinPlus', array('file' => $this->_settings['path']));
		if (!class_exists('JSMinPlus')) {
			throw new Exception(sprintf('Cannot not load filter class "%s".', 'JsMinPlus'));
		}
		return JSMinPlus::minify($content);
	}
}
