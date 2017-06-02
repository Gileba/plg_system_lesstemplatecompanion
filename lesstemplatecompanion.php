<?php
/**
 * @package		System Plugin - Less Template Companion, an automatic Less compiler for developers and users
 * @version		0.1.0-alpha.9
 * @author		Gijs Lamon
 * @copyright	(C) 2017 Gijs Lamon
 * @license		GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Based on the works of Andreas Tasch (https://github.com/ndeet/plg_system_less) and Thomas Hunziker (https://github.com/Bakual/Allrounder)
 */

// no direct access
defined('_JEXEC') or die();

/**
 * Plugin checks and compiles updated .less files on page load and on template style save.
 * Give your users the ability to set variables as template parameter and removing the need to manually compile .less files ever again.
 *
 * JLess compiler uses lessphp; see http://leafo.net/lessphp/
 *
 * @since  1.0
 */
class plgSystemLessTemplateCompanion extends JPlugin
{
	/**
	 * @var $app
	 * @var $lessFile	Origin file
	 * @var $cssFile	Destination file
	 */
	protected $app;
	protected $lessFile		= '';
	protected $cssFile		= '';
	protected $cacheFile		= '';
	protected $templatePath	= '';

	/**
	 * override constructor to load classes as soon as possible
	 * @param $subject
	 * @param $config
	 */
	public function __construct(&$subject, $config)
	{
		// trigger parent constructor first so params get set
		parent::__construct($subject, $config);

		$client = $this->app->isSite() ? JPATH_SITE : JPATH_ADMINISTRATOR;
		$this->templatePath = $client . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $this->app->getTemplate() . DIRECTORY_SEPARATOR;

		$this->lessFile     = $this->templatePath . 'less/template.less';
		$this->cssFile 		= $this->templatePath . 'css/template.css';

		// load config file
		$config = JFactory::getConfig();

		//path to temp folder
		$tmpPath = $config->get('tmp_path');

		//load chached file
		$this->cacheFile = $tmpPath . DIRECTORY_SEPARATOR . $this->app->getTemplate() . "_" . basename($this->lessFile) . ".cache";

	}

	/**
	 * Compile .less files on change
	 */
	public function onBeforeRender()
	{
		$table		= $this->app->getTemplate(true);

		// 0 = frontend only
		// 1 = backend only
		// 2 = front + backend
		$mode = $this->params->get('mode', 0);

		//check if .less file exists and is readable
		if (is_readable($this->lessFile))
		{
			// Check run conditions
			if (($this->app->isSite() && $mode === '1') || ($this->app->isAdmin() && $mode === '0')) return false;

			$this->compileLess($table, $this->params->get('less_force'));
		}
		return false;
	}

	/**
	 * Compile .less files on template style change
	 *
	 * @param   string  $context  Context of the data
	 * @param   object  $table    Table object
	 * @param   bool    $isNew    New entry or edit
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function onExtensionAfterSave($context, $table, $isNew)
	{
		if ($context != 'com_templates.style' && $context != 'com_advancedtemplates.style')
		{
			return;
		}
		
		if (!(is_object($table->params))) $table->params = $this->paramsToObject($table->params);

		// Check if .less file exists and is readable
		if (is_readable($this->lessFile))
		{
			$this->compileLess($table, true);
		}
	}
	
	/**
	 * Compile .less files
	 *
	 * @param   object  $table  Table object
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function compileLess($table, $force)
	{
		$cache = $this->getCache();

		// Instantiate new JLess compiler
		$less = new JLess;

		// Preserve comments
		$less->setPreserveComments($this->params->get('less_comments'));
		
		// Formatter
		switch ($this->params->get('less_compress')) {
			case 'Joomla':
				$formatter = new JLessFormatterJoomla;
				$less->setFormatter($formatter);
			default:
				$less->setFormatter($this->params->get('less_compress'));
		}

		$less->setVariables($this->setLessVariables($table->params->toArray()));

		$less->addImportDir($this->templatePath . "/less");
		
		//compile cache file
		$newCache = $less->cachedCompile($cache, $force);

		if (!is_array($cache) || $newCache["updated"] > $cache["updated"])
		{
			JFile::write($this->cacheFile, serialize($newCache));
			JFile::write($this->cssFile, $newCache['compiled']);
		}
	}

	/**
	 * Convert the params to an object
	 *
	 * @param   String  $params  the string to convert
	 *
	 * @return  Object
	 *
	 * @since   1.0
	 */
	private function paramsToObject($params)
	{
		if (is_string($params))
		{
			$registry = new \Joomla\Registry\Registry;
			$registry->loadString($params);
			$params = $registry;
		}

		return $params;
	}
	
	private function getCache()
	{
		if (file_exists($this->cacheFile))
		{
			$tmpCache = unserialize(file_get_contents($this->cacheFile));
			if ($tmpCache['root'] === $this->lessFile)
			{
				$cache = $tmpCache;
				return $cache;
			}
			return $this->lessFile;
		}
		return $this->lessFile;
	}
	
	/**
	 * Convert the params to an object
	 *
	 * @param   Array	$params  		an array with template params
	 *
	 * @return  Array	$lessParams		a sanitised array of specific less params
	 *
	 * @since   1.0
	 */
	private function setLessVariables($params) {
		$lessParams = array();
		
		// Sanitising params for LESS
		foreach ($params as $key => $value)
		{
			// Select useful params
			if (substr( $key, 0, 4 ) === "ltc_") {
				// Trim whitespaces
				$value = trim($value);
			
				// Adding quotes around variable so it's threaten as string if a slash is in it.
				if (strpos($value, '/') !== false)
				{
					$value = '"' . $value . '"';
				}
				
				// Quoting empty values as they break the compiler
				if ($value == '')
				{
					$value = '""';
				}
				
				// Add variable to return list
				$lessParams[substr($key, 4, strlen($key))] = $value;
			}
		}
		
		return $lessParams;
	}
}
