<?php
App::uses('Shell', 'Console');
App::uses('AssetConfig', 'AssetCompress.Lib');
App::uses('AssetCompiler', 'AssetCompress.Lib');
App::uses('AssetCache', 'AssetCompress.Lib');

App::uses('Folder', 'Utility');

/**
 * Asset Compress Shell
 *
 * Assists in clearing and creating the build files this plugin makes.
 *
 * @package AssetCompress
 */
class AssetCompressShell extends Shell {

	public $tasks = array('AssetCompress.AssetBuild');

/**
 * Create the configuration object used in other classes.
 *
 */
	public function startup() {
		parent::startup();

		AssetConfig::clearAllCachedKeys();
		$this->_Config = AssetConfig::buildFromIniFile($this->params['config']);
		$this->AssetBuild->setThemes($this->_findThemes());
		$this->out();
	}

/**
 * Builds all the files defined in the build file.
 *
 * @return void
 */
	public function build() {
		$this->out('Building files defined in the ini file');
		$this->hr();
		$this->build_ini();

		$this->out();
		$this->out('Building files in views');
		$this->hr();
		$this->build_dynamic();
	}

	public function build_ini() {
		$this->AssetBuild->setConfig($this->_Config);
		$this->AssetBuild->buildIni();
	}

	public function build_dynamic() {
		$this->AssetBuild->setConfig($this->_Config);
		$viewpaths = App::path('View');
		$this->AssetBuild->buildDynamic($viewpaths);
	}

	public function watch(){
		$this->AssetBuild->setConfig($this->_Config);

		$this->out('Scanning Files Defined in the INI File');
		$this->hr();

		$_buildFiles = array_flip( array_merge(
			$this->_Config->targets('js'),
			$this->_Config->targets('css')
			) );

		$buildFiles = array();
		foreach($_buildFiles as $build => $files){

			// We have to rebuild part of the isFresh scanner here
			// Because I don't want to screw up any dependencies
			// TODO: build into AssetCache::isFresh();
			$ext = $this->_Config->getExt($build);
			$files = $this->_Config->files($build);
			$theme = $this->_Config->theme();
			$target = $this->AssetBuild->Cacher->buildFileName($build);
			$buildFile = $this->_Config->cachePath($ext) . $target;
			if (!file_exists($buildFile)) {
				return false;
			}
			$Scanner = new AssetScanner($this->_Config->paths($ext, $target), $theme);

			$paths = array();
			foreach($files as $file){
				$paths[] = $path = $Scanner->find($file);
				$directory =  dirname($path);

				// Do the Magic CSS import building
				if($ext == 'css'){
					$handle = @fopen($path, 'rb');
					if ($handle) {
						while(( $line= fgets($handle)) !== false){
							if(strpos($line, '@import') !== false && strpos($line, 'http') == false){

								preg_match_all('/"(.*)"/', trim( str_replace('@import', '', $line) ), $matches);
								if(!empty($matches[1][0])){
									// Attach to the paths[] array
									$importPath = realpath($directory . DS . $matches[1][0]);
									if($importPath){
										$paths[] = $importPath;
									}

								}
							}
						}
						fclose($handle);
					}
				}
			}
			$buildFiles[ $build ] = $paths;

		}

		$this->out('Starting Watching of all assets');
		$this->out('Press Ctrl-C to stop watching');
		$this->hr();

		while(true){
			foreach($buildFiles as $build => $paths){
				$rebuild = false;

				// Get the True name, because we don't cache it
				$ext = $this->_Config->getExt($build);
				$files = $this->_Config->files($build);
				$theme = $this->_Config->theme();
				$target = $this->AssetBuild->Cacher->buildFileName($build);
				$buildFile = $this->_Config->cachePath($ext) . $target;

				$buildTime = filemtime($buildFile);

				foreach($paths as $path){
					if ($Scanner->isRemote($path)) {
						$time = $this->getRemoteFileLastModified($path);
					} else {
						$time = filemtime($path);
					}
					if ($time === false || $time >= $buildTime) {
						$rebuild = true;
						break;
					}
				}

				if($rebuild){
					$this->AssetBuild->Cacher->setTimestamp($build, 0);
					$name = $this->AssetBuild->Cacher->buildFileName($build);
					try {
						$this->out('<success>Saving file</success> for ' . $name);
						$contents = $this->AssetBuild->Compiler->generate($build);
						$this->AssetBuild->Cacher->write($build, $contents);
					} catch (Exception $e) {
						$this->err('Error: ' . $e->getMessage());
					}
				}
			}

			sleep(5);
		}

		$this->out('Finished Compiling');
		$this->hr();


	}

/**
 * Clears the build directories for both CSS and JS
 *
 * @return void
 */
	public function clear() {
		$this->clear_build_ts();

		$this->out('Clearing Javascript build files:');
		$this->hr();
		$this->_clearBuilds('js');

		$this->out('');
		$this->out('Clearing CSS build files:');
		$this->hr();
		$this->_clearBuilds('css');

		$this->out();
		$this->out('<success>Complete</success>');
	}

/**
 * Clears out all the cache keys associated with asset_compress.
 *
 * Note: method really does nothing here because keys are cleared in startup.
 * This method exists for times when you just want to clear the cache keys
 * associated with asset_compress
 */
	public function clear_cache() {
		$this->out('Clearing all cache keys:');
		$this->hr();
	}

/**
 * Clears the build timestamp. Try to clear it out even if they do not have ts file enabled in
 * the INI.
 *
 * build timestamp file is only created when build() is run from this shell
 */
	public function clear_build_ts() {
		$this->out('Clearing build timestamp.');
		$this->out();
		AssetConfig::clearBuildTimeStamp();
	}

/**
 * clear the builds for a specific extension.
 *
 * @return void
 */
	protected function _clearBuilds($ext) {
		$themes = $this->_findThemes();
		$targets = $this->_Config->targets($ext);
		if (empty($targets)) {
			$this->err('No ' . $ext . ' build files defined, skipping');
			return;
		}
		$path = $this->_Config->cachePath($ext);
		if (!file_exists($path)) {
			$this->err('Build directory ' . $path . ' for ' . $ext . ' does not exist.');
			return;
		}
		$dir = new DirectoryIterator($path);
		foreach ($dir as $file) {
			$name = $base = $file->getFilename();
			if (in_array($name, array('.', '..'))) {
				continue;
			}
			// timestampped files.
			if (preg_match('/^.*\.v\d+\.[a-z]+$/', $name)) {
				list($base, $v, $ext) = explode('.', $name, 3);
				$base = $base . '.' . $ext;
			}
			// themed files
			foreach ($themes as $theme) {
				if (strpos($base, $theme) === 0) {
					list($themePrefix, $base) = explode('-', $base);
				}
			}
			if (in_array($base, $targets)) {
				$this->out(' - Deleting ' . $path . $name);
				unlink($path . $name);
				continue;
			}
		}
	}

/**
 * Find all the themes in an application.
 * This is used to generate theme asset builds.
 *
 * @return array Array of theme names.
 */
	protected function _findThemes() {
		$viewpaths = App::path('View');
		$themes = array();
		foreach ($viewpaths as $path) {
			if (is_dir($path . 'Themed')) {
				$Folder = new Folder($path . 'Themed');
				list($dirs, $files) = $Folder->read(false, true);
				$themes = array_merge($themes, $dirs);
			}
		}
		return $themes;
	}

/**
 * get the option parser.
 *
 * @return void
 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();
		return $parser->description(array(
			'Asset Compress Shell',
			'',
			'Builds and clears assets defined in your asset_compress.ini',
			'file and in your view files.'
		))->addSubcommand('clear', array(
			'help' => 'Clears all builds defined in the ini file.'
		))->addSubcommand('build', array(
			'help' => 'Generate all builds defined in the ini and view files.'
		))->addSubcommand('build_ini', array(
			'help' => 'Generate only build files defined in the ini file.'
		))->addSubcommand('build_dynamic', array(
			'help' => 'Build build files defined in view files.'
		))->addOption('config', array(
			'help' => 'Choose the config file to use.',
			'short' => 'c',
			'default' => APP . 'Config' . DS . 'asset_compress.ini'
		))->addOption('force', array(
			'help' => 'Force assets to rebuild. Ignores timestamp rules.',
			'short' => 'f',
			'boolean' => true
		));
	}
}
