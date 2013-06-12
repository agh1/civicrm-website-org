<?php

/**
 * Maintain a set of markup/templates to inject inside various regions
 */
class CRM_Core_Region {
  static private $_instances = NULL;

  /**
   * Obtain the content for a given region
   *
   * @param string $name
   * @param bool $autocreate whether to automatically create an empty region
   * @return CRM_Core_Region
   */
  static function &instance($name, $autocreate = TRUE) {
    if ( $autocreate && ! isset( self::$_instances[$name] ) ) {
      self::$_instances[$name] = new CRM_Core_Region($name);
    }
    return self::$_instances[$name];
  }

  /**
   * Symbolic name of this region
   *
   * @var string
   */
  var $_name;

  /**
   * List of snippets to inject within region
   *
   * @var array; e.g. $this->_snippets[3]['type'] = 'template';
   */
  var $_snippets;

  /**
   * Whether the snippets array has been sorted
   *
   * @var boolean
   */
  var $_isSorted;

  public function __construct($name) {
    // Templates injected into regions should normally be file names, but sometimes inline notation is handy.
    require_once 'CRM/Core/Smarty/resources/String.php';
    civicrm_smarty_register_string_resource( );

    $this->_name = $name;
    $this->_snippets = array();

    // Placeholder which represents any of the default content generated by the main Smarty template
    $this->add(array(
      'name' => 'default',
      'type' => 'markup',
      'markup' => '',
      'weight' => 0,
    ));
    $this->_isSorted = TRUE;
  }

  /**
   * Add a snippet of content to a region
   *
   * @code
   * CRM_Core_Region::instance('page-header')->add(array(
   *   'markup' => '<div style="color:red">Hello!</div>',
   * ));
   * CRM_Core_Region::instance('page-header')->add(array(
   *   'script' => 'alert("Hello");',
   * ));
   * CRM_Core_Region::instance('page-header')->add(array(
   *   'template' => 'CRM/Myextension/Extra.tpl',
   * ));
   * CRM_Core_Region::instance('page-header')->add(array(
   *   'callback' => 'myextension_callback_function',
   * ));
   * @endcode
   *
   * Note: This function does not perform any extra encoding of markup, script code, or etc. If
   * you're passing in user-data, you must clean it yourself.
   *
   * @param $snippet array; keys:
   *   - type: string (auto-detected for markup, template, callback, script, scriptUrl, jquery, style, styleUrl)
   *   - name: string, optional
   *   - weight: int, optional; default=1
   *   - disabled: int, optional; default=0
   *   - markup: string, HTML; required (for type==markup)
   *   - template: string, path; required (for type==template)
   *   - callback: mixed; required (for type==callback)
   *   - arguments: array, optional (for type==callback)
   *   - script: string, Javascript code
   *   - scriptUrl: string, URL of a Javascript file
   *   - jquery: string, Javascript code which runs inside a jQuery(function($){...}); block
   *   - style: string, CSS code
   *   - styleUrl: string, URL of a CSS file
   */
  public function add($snippet) {
    static $types = array('markup', 'template', 'callback', 'scriptUrl', 'script', 'jquery', 'style', 'styleUrl');
    $defaults = array(
      'region' => $this->_name,
      'weight' => 1,
      'disabled' => FALSE,
    );
    $snippet += $defaults;
    if (!isset($snippet['type'])) {
      foreach ($types as $type) {
        // auto-detect
        if (isset($snippet[$type])) {
          $snippet['type'] = $type;
          break;
        }
      }
    }
    if (!isset($snippet['name'])) {
      $snippet['name'] = count($this->_snippets);
    }
    $this->_snippets[ $snippet['name'] ] = $snippet;
    $this->_isSorted = FALSE;
    return $snippet;
  }

  public function update($name, $snippet) {
    $this->_snippets[$name] = array_merge($this->_snippets[$name], $snippet);
    $this->_isSorted = FALSE;
  }

  public function &get($name) {
    return @$this->_snippets[$name];
  }

  /**
   * Render all the snippets in a region
   *
   * @param string $default HTML, the initial content of the region
   * @param bool $allowCmsOverride allow CMS to override rendering of region
   * @return string, HTML
   */
  public function render($default, $allowCmsOverride = TRUE) {
    // $default is just another part of the region
    if (is_array($this->_snippets['default'])) {
      $this->_snippets['default']['markup'] = $default;
    }
    // We hand as much of the work off to the CMS as possible
    $cms = CRM_Core_Config::singleton()->userSystem;

    if (!$this->_isSorted) {
      uasort($this->_snippets, array('CRM_Core_Region', '_cmpSnippet'));
      $this->_isSorted = TRUE;
    }

    $smarty = CRM_Core_Smarty::singleton();
    $html = '';
    foreach ($this->_snippets as $snippet) {
      if ($snippet['disabled']) {
        continue;
      }
      switch($snippet['type']) {
        case 'markup':
          $html .= $snippet['markup'];
          break;
        case 'template':
          $tmp = $smarty->get_template_vars('snippet');
          $smarty->assign('snippet', $snippet);
          $html .= $smarty->fetch($snippet['template']);
          $smarty->assign('snippet', $tmp);
          break;
        case 'callback':
          $args = isset($snippet['arguments']) ? $snippet['arguments'] : array(&$snippet, &$html);
          $html .= call_user_func_array($snippet['callback'], $args);
          break;
        case 'scriptUrl':
          if (!$allowCmsOverride || !$cms->addScriptUrl($snippet['scriptUrl'], $this->_name)) {
            $html .= sprintf("<script type=\"text/javascript\" src=\"%s\">\n</script>\n", $snippet['scriptUrl']);
          }
          break;
        case 'jquery':
          $snippet['script'] = sprintf("cj(function(\$){\n%s\n});", $snippet['jquery']);
          // no break - continue processing as script
        case 'script':
          if (!$allowCmsOverride || !$cms->addScript($snippet['script'], $this->_name)) {
            $html .= sprintf("<script type=\"text/javascript\">\n%s\n</script>\n", $snippet['script']);
          }
          break;
        case 'styleUrl':
          if (!$allowCmsOverride || !$cms->addStyleUrl($snippet['styleUrl'], $this->_name)) {
            $html .= sprintf("<link href=\"%s\" rel=\"stylesheet\" type=\"text/css\"/>\n", $snippet['styleUrl']);
          }
          break;
        case 'style':
          if (!$allowCmsOverride || !$cms->addStyle($snippet['style'], $this->_name)) {
            $html .= sprintf("<style type=\"text/css\">\n%s\n</style>\n", $snippet['style']);
          }
          break;
        default:
          require_once 'CRM/Core/Error.php';
          CRM_Core_Error::fatal( ts( 'Snippet type %1 is unrecognized',
                     array( 1 => $snippet['type'] ) ) );
      }
    }
    return $html;
  }

  static function _cmpSnippet($a, $b) {
    if ($a['weight'] < $b['weight']) return -1;
    if ($a['weight'] > $b['weight']) return 1;
    // fallback to name sort; don't really want to do this, but it makes results more stable
    if ($a['name'] < $b['name']) return -1;
    if ($a['name'] > $b['name']) return 1;
    return 0;
  }

  /**
   * Add block of static HTML to a region
   *
   * @param string $markup HTML
   *
  public function addMarkup($markup) {
    return $this->add(array(
      'type' => 'markup',
      'markup' => $markup,
    ));
  }

  /**
   * Add a Smarty template file to a region
   *
   * Note: File is not evaluated until the page is rendered
   *
   * @param string $template path to the Smarty template file
   *
  public function addTemplate($template) {
    return $this->add(array(
      'type' => 'template',
      'template' => $template,
    ));
  }

  /**
   * Use a callback function to extend a region
   *
   * @param mixed $callback
   * @param array $arguments optional, array of parameters for callback; if omitted, the default arguments are ($snippetSpec, $html)
   *
  public function addCallback($callback, $arguments = FALSE) {
    return $this->add(array(
      'type' => 'callback',
      'callback' => $callback,
      'arguments' => $arguments,
    ));
  }
  */
}