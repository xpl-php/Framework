<?php
/**
 * @package Phpf.Routes
 */

namespace Phpf\Routes;

use Phpf\Util\iEventable;
use Phpf\Event\Container as Events;
use Phpf\Http\Request;
use Phpf\Http\Response;
use Phpf\Util\Reflection\Callback;

class Router implements iEventable
{

	protected $routes = array();

	protected $vars = array(
		'segment' => '([^_/][^/]+)', 
		'words' => '(\w\-+)', 
		'int' => '(\d+)', 
		'str' => '(.+?)', 
		'any' => '(.?.+?)', 
	);

	/**
	 * File extensions to strip from URLs.
	 * If matched, will set Request $content_type property
	 * and override any set by the header.
	 */
	protected $strip_extensions = 'html|jsonp|json|xml|php';

	protected $request;

	protected $response;

	protected $route;

	protected $events;

	protected $route_catchers = array();

	public function __construct(Events &$events) {
		
		$this->events = &$events;

		// Default error event
		$this->on('router.http.404', function($event, $exception, $route, $request, $response) {
			if (! $event->isDefaultPrevented()) {
				$response->sendStatusHeader();
				$response->setBody($exception->getMessage());
				$response->send();
			}
		});
	}

	/**
	 * Matches and routes a request URI.
	 */
	public function dispatch(Request &$request, Response &$response) {

		timer_start('router');
		
		$this->request = &$request;
		$this->response = &$response;
		
		if ($this->match()) {
			
			timer_end('router');
			
			$reflect = new Callback($this->route->getCallback());
			$this->trigger('dispatch:before', $this->route, $this->request, $this->response);

			try {

				$reflect->reflectParameters($request->getParams());

			} catch (\Phpf\Util\Reflection\Exception\MissingParam $e) {

				$msg = str_replace('reflection', 'required route', $e->getMessage());
				$exception = new Exception\MissingParam($msg);
				$this->error(404, $exception, $this->route);
			}

			$reflect->invoke();
			$this->catchRoute();
			$this->trigger('dispatch:after', $this->route, $this->request, $this->response);
		}

		$this->error(404, new Exception\UnknownRoute('Unknown route'), null);
	}

	/**
	 * Gets Request object
	 */
	public function getRequest() {
		return $this->request;
	}

	/**
	 * Gets Response object
	 */
	public function getResponse() {
		return $this->response;
	}

	/**
	 * Gets matched Route object
	 */
	public function getRoute() {
		return $this->route;
	}

	/**
	 * Returns array of route objects, their URI as the key.
	 * Can return a specified priority group, otherwise returns all.
	 */
	public function getRoutes($priority = null) {
		if ($priority !== null)
			return isset($this->routes[$priority]) ? $this->routes[$priority] : array();
		return $this->routes;
	}

	/**
	 * Returns regex for a query var
	 */
	public function getRegex($key) {
		return isset($this->vars[$key]) ? $this->vars[$key] : '';
	}

	/**
	 * Returns array of query vars and regexes
	 */
	public function getVars() {
		return $this->vars;
	}

	/**
	 * Adds query var and regex
	 *
	 * @param string $name The query var name
	 * @param string $regex The var's regex, or another registered var name
	 */
	public function addVar($name, $regex) {
		$this->vars[$name] = $regex;
		return $this;
	}

	/**
	 * Adds array of query vars and regexes
	 */
	public function addVars(array $vars) {
		foreach ( $vars as $name => $regex ) {
			$this->addVar($name, $regex);
		}
		return $this;
	}

	/**
	 * Adds a single route
	 */
	public function addRoute($uri, array $args, $priority = 10) {
		$route = new Route($uri, $args);
		$this->routes[$priority][$uri] = $route;
		return $this;
	}

	/**
	 * Adds a group of routes.
	 *
	 * Group can already exist in same or other grouping (priority).
	 *
	 * @param string $controller The lowercase controller name
	 * @param array $routes Array of 'route => callback'
	 * @param int $priority The group priority level
	 * @param string $position The routes' position within the group, if exists
	 * already
	 */
	public function addRoutes(array $routes, $priority = 10) {

		$objects = array();

		foreach ( $routes as $uri => $args ) {
			$objects[$uri] = new Route($uri, $args);
		}

		if (empty($this->routes[$priority])) {
			$this->routes[$priority] = $objects;
		} else {
			$this->routes[$priority] = array_merge($objects, $this->routes[$priority]);
		}

		return true;
	}

	/** @alias addRoute() */
	public function route($uri, array $args, $priority = 10) {
		return $this->addRoute($uri, $args, $priority);
	}

	/**
	 * Add a group of routes under an endpoint/namespace
	 * 
	 * @param string $path Endpoint path
	 * @param Closure $callback Closure that returns the routes
	 */
	public function endpoint($path, \Closure $callback) {
		$this->endpoints[$path] = $callback;
		return $this;
	}

	/**
	 * Set a controller class to use for the current endpoint.
	 * @see matchEndpoints()
	 */
	public function setController($class) {
		$this->ep_controller_class = $class;
		return $this;
	}

	/**
	 * Adds an extension to strip from URIs
	 */
	public function stripExtension($extension) {
		$this->strip_extensions[] = ltrim(strtolower($extension), '.');
		return $this;
	}

	/**
	 * Adds a route catcher
	 */
	public function addCatcher(Catcher\AbstractCatcher $catcher, $priority = null) {

		if (! isset($priority)) {
			if (! empty($this->route_catchers)) {
				$priority = max(array_keys($this->route_catchers)) + 1;
			} else {
				$priority = 10;
			}
		}

		$this->route_catchers[$priority] = $catcher;

		return $this;
	}

	/**
	 * Adds an action (event) callback. Also used for errors.
	 *
	 * Router events use the syntax 'router.<event>'
	 */
	public function on($action, $call, $priority = 10) {
		$this->events->on('router.'.$action, $call, $priority);
		return $this;
	}

	/**
	 * Calls action callback(s).
	 */
	public function trigger($action /* [, $arg1, ...] */) {
		$args = func_get_args();
		array_shift($args);
		$action = 'router.' . $action;
		return $this->events->triggerArray($action, $args);
	}

	/**
	 * Sends an error using an error handler based on status code, if exists.
	 *
	 * Router error events use the syntax 'router.http.<code>'
	 */
	public function error($code, \Exception $exception, Route $route = null) {
		$this->response->setStatus($code);
		$this->trigger('http.'.$code, $exception, $route, $this->request, $this->response);
		exit;
	}

	/**
	 * Matches request URI to a route.
	 */
	protected function match() {

		$http_method = $this->request->getMethod();

		// Remove content type file extensions
		$uri = $this->stripExtensions($this->request->getUri(), $type);

		if (! empty($type)) {
			$this->request->setContentType($type);
		}

		if (! empty($this->endpoints)) {
			if ($this->matchEndpoints($uri, $http_method)) {
				return true;
			}
		}

		if (! empty($this->routes)) {

			ksort($this->routes);

			foreach ( $this->routes as $group ) {
				foreach ( $group as $Route ) {
					if ($this->matchRoute($Route, $uri, $http_method)) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Searches endpoints for route match.
	 */
	protected function matchEndpoints($uri, $http_method) {

		foreach ( $this->endpoints as $path => $closure ) {

			if (0 === strpos($uri, $path)) {

				$this->routes[$path] = array();
				$routes = $closure($this);

				foreach ( $routes as $epUri => $array ) {

					if (isset($this->ep_controller_class)) {
						// Closure has set a controller class to use for all routes.
						if (is_string($array)) {
							$action = $array;
							$array = array();
							$array['controller'] = $this->ep_controller_class;
							$array['action'] = $action;
						} elseif (isset($array['action'])) {
							$array['controller'] = $this->ep_controller_class;
						}

						$array['callback'] = array($array['controller'], $array['action']);
					}

					$array['endpoint'] = trim($path, '/');

					$route = $this->routes[$path][$path.$epUri] = new Route($path.$epUri, $array);

					if ($this->matchRoute($route, $uri, $http_method)) {
						return true;
					}
				}

				unset($this->ep_controller_class);
			}
		}

		return false;
	}

	/**
	 * Determines if a given Route URI matches the request URI.
	 * If match, sets Router property $route and assembles the matched query
	 * vars and adds them to Request property $path_params. However, if
	 * the HTTP method is not allowed, a 405 Status error is returned.
	 */
	protected function matchRoute(Route $route, $uri, $http_method) {

		$qvs = array();
		$route_uri = $this->parseRoute($route->uri, $qvs);

		if (preg_match('#^/?'.$route_uri.'/?$#i', $uri, $route_vars)) {

			if (! $route->isMethodAllowed($http_method)) {

				$exception = new Exception\HttpMethodNotAllowed;
				$exception->setRequestedMethod($http_method);
				$exception->setAllowedMethods($route->getMethods());

				$this->response->addHeader('Allow', $exception->getAllowedMethodsString());

				$this->error(405, $exception, $route);
			}

			$this->route = &$route;

			unset($route_vars[0]);

			if (! empty($qvs) && ! empty($route_vars)) {
				$this->request->setPathParams(array_combine($qvs, $route_vars));
			}

			return true;
		}

		return false;
	}

	/**
	 * Parses a route URI, changing query vars to regex and adding keys to $vars.
	 */
	protected function parseRoute($uri, &$vars = array()) {
		
		// find vars either renamed or inline
		if (preg_match_all('/<(\w+):(.+?)>/', $uri, $matches)) {
			
			// easier to use full match for str_replace() vs re-creating the pattern
			foreach ( $matches[0] as $i => $str ) {

				if ('' !== $regex = $this->getRegex($matches[2][$i])) {
					// Renamed: <id:int>
					$uri = str_replace($str, $regex, $uri);
					$vars[$matches[2][$i]] = $matches[1][$i];
				} else {
					// Inline: <year:[\d]{4}>
					$uri = str_replace($str, '('.$matches[2][$i].')', $uri);
					$vars[$matches[1][$i]] = $matches[1][$i];
				}
			}
		}
		
		// find registered <var>'s
		if (preg_match_all('/<(\w+)>/', $uri, $matches2)) {

			foreach ( $matches2[1] as $i => $str ) {

				if ($regex = $this->getRegex($str)) {
					$uri = str_replace('<'.$str.'>', '('.$regex.')', $uri);
					$vars[$str] = $str;
				} // @TODO error if route var is unknown?
			}
		}

		return $uri;
	}

	/**
	 * Catches and processes caught routes
	 */
	protected function catchRoute() {

		if (empty($this->route_catchers))
			return;

		ksort($this->route_catchers);

		foreach ( $this->route_catchers as $catcher ) {

			$catcher->init($this, $this->request);

			if ($catcher->catchRoute($this->route, $this->request)) {
				$catcher->process($this->response);
			}
		}
	}

	/**
	 * Matches filetypes at the end of a string (usually URI) and removes them.
	 */
	protected function stripExtensions($string, &$match = null) {

		if (preg_match("/[\.|\/]($this->strip_extensions)/", $string, $matches)) {
			$match = $matches[1];
			// remove extension and separator
			$string = str_replace(substr($matches[0], 0, 1).$match, '', $string);
		}

		return $string;
	}

}
