<?php
/**
 * MINZ - Copyright 2011 Marien Fressinaud
 * Sous licence AGPL3 <http://www.gnu.org/licenses/>
*/

/**
 * The Minz_ActionController class is a controller in the MVC paradigm
 */
class Minz_ActionController {

	/** @var array<string,string> */
	private static $csp_default = [
		'default-src' => "'self'",
	];

	/** @var array<string,string> */
	private $csp_policies;

	/** @var Minz_View */
	protected $view;

	/**
	 * Gives the possibility to override the default View type.
	 * @var class-string
	 */
	public static $viewType = 'Minz_View';

	public function __construct () {
		$this->csp_policies = self::$csp_default;
		if (class_exists(self::$viewType)) {
			$this->view = new self::$viewType();
		} else {
			$this->view = new Minz_View();
		}
		$view_path = Minz_Request::controllerName() . '/' . Minz_Request::actionName() . '.phtml';
		$this->view->_path($view_path);
		$this->view->attributeParams ();
	}

	/**
	 * Getteur
	 */
	public function view(): Minz_View {
		return $this->view;
	}

	/**
	 * Set default CSP policies.
	 * @param array<string,string> $policies An array where keys are directives and values are sources.
	 */
	public static function _defaultCsp(array $policies): void {
		if (!isset($policies['default-src'])) {
			Minz_Log::warning('Default CSP policy is not declared', ADMIN_LOG);
		}
		self::$csp_default = $policies;
	}

	/**
	 * Set CSP policies.
	 *
	 * A default-src directive should always be given.
	 *
	 * References:
	 * - https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
	 * - https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/default-src
	 *
	 * @param array<string,string> $policies An array where keys are directives and values are sources.
	 */
	protected function _csp(array $policies): void {
		if (!isset($policies['default-src'])) {
			$action = Minz_Request::controllerName() . '#' . Minz_Request::actionName();
			Minz_Log::warning(
				"Default CSP policy is not declared for action {$action}.",
				ADMIN_LOG
			);
		}
		$this->csp_policies = $policies;
	}

	/**
	 * Send HTTP Content-Security-Policy header based on declared policies.
	 */
	public function declareCspHeader(): void {
		$policies = [];
		foreach ($this->csp_policies as $directive => $sources) {
			$policies[] = $directive . ' ' . $sources;
		}
		header('Content-Security-Policy: ' . implode('; ', $policies));
	}

	/**
	 * Méthodes à redéfinir (ou non) par héritage
	 * firstAction est la première méthode exécutée par le Dispatcher
	 * lastAction est la dernière
	 */
	public function init(): void { }
	public function firstAction(): void { }
	public function lastAction(): void { }
}
