<?php

/**
 * @package x20
 * @author Joshua L. Johnson <josh@ua1.us>
 * @link http://labs.ua1.us
 * @copyright Copyright 2016, Joshua L. Johnson
 * @license MIT
 */

namespace x20\core;

//bootstrap core z20 files
require_once __DIR__.'/x20graph.php';
require_once __DIR__.'/z20module.php';
require_once __DIR__.'/z20service.php';

use Exception;
use x20\core\x20graph;

/**
 * The z20 Class is what makes z20 possible. This class handles the
 * entire Dependency Injection environment.
 */
class z20
{
    /**
     * Holds the instantiated z20 singleton object.
     *
     * @static
     * @var z20
     */
    private static $z20;

    /**
     * An array that contains all registered module objects.
     *
     * @var array
     */
    private $modules;

    /**
     * An array that contains all of the module IDs that were loaded
     * into the runtime execution environment of z20.
     *
     * @var array
     */
    private $loadedModules;

    /**
     * An array of factory constructors that were registered in z20 modules
     * that were loaded during the runtime execution of z20.
     *
     * @var array
     */
    private $factoryServices;

    /**
     * An array of instantiated singleton object services that were defined as
     * singleton build types in modules that were loaded during the runtime
     * execution of z20.
     *
     * @var array
     */
    private $singletonServices;

    /**
     * An array that defines all registered services and how to handle them
     * within z20.
     *
     * @var array
     */
    private $serviceBlueprints;

    /**
     * Contains an object that represents the module dependency tree as a
     * graph.
     *
     * @var dependencyGraph
     */
    private $moduleDependencyGraph;

    /**
     * Contains an object that represents the service dependency tree as a
     * graph.
     *
     * @var dependencyGraph
     */
    private $serviceDependencyGraph;

    /**
     * z20 constructor sets up z20 properties to allow you to start to register
     * modules and services.
     */
    private function __construct()
    {
        $this->modules = array();
        $this->loadedModules = array();
        $this->factoryServices = array();
        $this->singletonServices = array();
        $this->serviceBlueprints = array();
        $this->moduleDependencyGraph = new dependencyGraph();
        $this->serviceDependencyGraph = new dependencyGraph();
    }

    /**
     * Gets a singleton instance of z20. First checks to see if an instance
     * exists. If not, it will create the instance. Then it will return the
     * one and only one instance of z20\core\z20.
     *
     * @static
     * @return z20
     */
    public static function get()
    {
        if (!isset(self::$z20) || !(self::$z20)) {
            self::$z20 = new self();
        }

        return self::$z20;
    }

    /**
     * This method is used to define modules and register services. If a module
     * does not exist, it will create the new module using the moduleInterface
     * factory and return the instantiated module to you for you to add
     * services.
     *
     * @param string $module_id    A unique ID that identifies the module
     * @param array  $dependencies An array of other dependent modules.
     * @return mixed The module object identified with $module_id
     * @throws Exception
     */
    public function module($module_id, $dependencies = array())
    {
        //if module object doesn't exists attempt to create it first and register dependencies
        if (!isset($this->modules[$module_id])) {
            //make sure we received $dependencies as an array!
            if (!is_array($dependencies)) {
                throw new Exception('When registering a module, its dependencies must be registered as an array of dependencies.');
            }
            $this->modules[$module_id] = new z20Module($module_id);
            //add module to dependency graph
            $this->moduleDependencyGraph->addResource($module_id);
            //add dependencies to module in graph
            $this->moduleDependencyGraph->addDependencies($module_id, $dependencies);
        }

        return $this->modules[$module_id];
    }

    /**
     * This method is used to force a module to be loaded into the z20 runtime
     * environment.
     *
     * @param string $module_id The unique ID that identifies the module you
     *                          intend to load into the z20 runtime environment
     *
     * @return z20
     */
    public function loadModule($module_id)
    {
        if (!in_array($module_id, $this->loadedModules)) {
            $this->_loadModule($module_id);
        }

        return $this;
    }

    /**
     * This method is used to inject a service anywhere you may need it. The
     * service will be returned as the instantiated service.
     *
     * @param string $service_id The unique ID that identifies the service you
     *                           intend to inject
     *
     * @return mixed The service object you identified by $service_id
     */
    public function injector($service_id)
    {
        return $this->_invokeService($service_id, true);
    }

    /**
     * This method is used to invoke a service anywhere you may need it.
     *
     * @param string $service_id The unique ID that identifies the service you
     *                           intend to inject
     */
    public function invoker($service_id)
    {
        $this->_invokeService($service_id);
    }

    /**
     * This method determines if a $service_id is a registered service.
     *
     * @param string $service_id A unique ID that identifies the service you
     *                           would like to validate
     *
     * @return bool If the service was registered
     */
    public function isService($service_id)
    {
        if (isset($this->serviceBlueprints[$service_id])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * This method determines the service type of a $service_id.
     *
     * @param string $service_id A unique ID that identifies the service you
     *                           would like to get the service type of.
     *
     * @return mixed The service type of the service you identified with
     *               $service_id
     */
    public function getServiceType($service_id)
    {
        if ($this->isService($service_id)) {
            return $this->serviceBlueprints[$service_id]['service_type'];
        } else {
            return false;
        }
    }

    /**
     * This method determines the build type of a $service_id.
     *
     * @param string $service_id A unique ID that identifies the service you
     *                           would like to get the build type of.
     *
     * @return mixed The build type of the service you identified with
     *               $service_id
     */
    public function getServiceBuildType($service_id)
    {
        if ($this->isService($service_id)) {
            return $this->serviceBlueprints[$service_id]['build_type'];
        } else {
            return false;
        }
    }

    /**
     * This method returns the service blueprints that provide details about
     * all of the services registered to the z20 runtime environment.
     *
     * @return array The service blueprints
     */
    public function getServiceBlueprints()
    {
        return $this->serviceBlueprints;
    }

    /**
     * This method is responsible for firing the modules execute services that were registered with
     * the module using the execute() method.
     */
    public function execute()
    {
        foreach ($this->loadedModules as $module_id) {
            if ($this->serviceDependencyGraph->isResource($module_id.'_exec')) {
                $this->invoker($module_id.'_exec');
            }
        }
    }

    /**
     * This method is used to reset the entire instance of z20 so that you
     * can iterate over objects in the same script. Anytime you would need more
     * than one z20 Runtime Environment, you would be required to reset z20 before
     * executing another runtime.
     */
    public function destroy()
    {
        self::$z20 = null;
    }

    /**
     * This method takes a module from being registered with z20 to loading the
     * module's services into the z20 runtime environment.
     *
     * @param string $module_id A unique ID of the module you would like to load
     *
     * @throws Exception
     */
    private function _loadModule($module_id)
    {
        //check to see if there are errors resolving dependencies
        $this->moduleDependencyGraph->runDependencyCheck($module_id);
        $error = $this->moduleDependencyGraph->getDependencyError();
        if (!$error) {
            $dependentModules = $this->moduleDependencyGraph->getDependencyResolveOrder();
            $this->moduleDependencyGraph->resetDepenencyCheck();
            //register all services with dependencyGraph and store service closure
            foreach ($dependentModules as $module_id) {
                if (!in_array($module_id, $this->loadedModules)) {
                    $this->loadedModules[] = $module_id;
                    $services = &$this->modules[$module_id]->services;
                    foreach ($services as $service) {
                        //register all services with the serviceDependencyGraph
                        $this->serviceDependencyGraph->addResource($service->service_id);
                        $this->serviceDependencyGraph->addDependencies($service->service_id, $service->dependencies);
                        //store service in $this->registeredServices
                        $this->factoryServices[$service->service_id] = $service->closure;
                        //store info on how to make the service
                        $this->serviceBlueprints[$service->service_id] = array(
                            'service_type' => $service->service_type,
                            'build_type' => $service->build_type,
                        );
                    }
                    //garbage collect
                    unset($this->modules[$module_id]);
                }
            }
            //run all modules' run services that defined run services.
            foreach ($dependentModules as $module_id) {
                if ($this->serviceDependencyGraph->isResource($module_id.'_run')) {
                    $this->_invokeService($module_id.'_run');
                }
            }
        } else {
            switch ($error['code']) {
                case 1:
                    throw new Exception('Could not find the module "' . $error['resource'] . '".');
                    break;

                case 2:
                    $error = 'While trying to resolve modules\'s dependencies, z20 has encountered a circular ' . 
                    'dependency resource error when resolving the module "' . $error['resource'] . '".';
                    throw new Exception($error);
                    break;
            }
        }
    }

    /**
     * This method is an internal way to invoke a single service and resolve
     * all of its dependencies.
     *
     * @param string $service_id A unique ID for the service you would like to
     *                           invoke.
     * @param bool   $return     A boolean value to determine if you want z20
     *                           to return the invoked service to you or not. Default false.
     *
     * @throws Exception
     */
    private function _invokeService($service_id, $return = false)
    {
        $this->serviceDependencyGraph->runDependencyCheck($service_id);
        $error = $this->serviceDependencyGraph->getDependencyError();
        if ($error) {
            switch ($error['code']) {
                case 1:
                    throw new Exception('Could not find service "'.$error['resource'].'".');
                    break;

                case 2:
                    throw new Exception('While trying to resolve a service\'s dependencies, z20 has encountered a circular dependency resource error when resolving the service "'.$error['resource'].'".');
                    break;
            }
        } else {
            $dependantServices = $this->serviceDependencyGraph->getDependencies($service_id);
            //dependency injection
            $di = array();
            //collect all required dependencies in $di to be injected
            foreach ($dependantServices as $service) {
                $di[] = $this->_resolveService($service);
            }
            //invoke requested service
            if (!$return) {
                $this->_instanciateService($service_id, $di);
            } else {
                return $this->_instanciateService($service_id, $di);
            }
            unset($di);
        }
    }

    /**
     * This method is used to resolve services' dependencies before
     * invoking the service.
     *
     * @param string $service_id A unique ID to identify the service you would
     *                           like to resolve.
     */
    private function _resolveService($service_id)
    {
        $dependencies = $this->serviceDependencyGraph->getDependencies($service_id);
        if (empty($dependencies)) {
            return $this->_instanciateService($service_id, array());
        } else {
            $di = array();
            foreach ($dependencies as $dependency) {
                //set $di[] with all dependencies and invoke
                $di[] = $this->_resolveService($dependency);
            }

            return $this->_instanciateService($service_id, $di);
        }
    }

    /**
     * This method is used to instantiate a service that has all of its
     * dependencies resolved.
     *
     * @param string $service_id           A unique ID of the service you would like to
     *                                     instantiate.
     * @param array  $resolvedDependencies An array that contains all of
     *                                     a services dependencies resolved.
     */
    private function _instanciateService($service_id, $resolvedDependencies)
    {
        $build_type = $this->getServiceBuildType($service_id);
        switch ($build_type) {
            case 'singleton':
                if (!isset($this->singletonServices[$service_id])) {
                    $dependantClosure = $this->factoryServices[$service_id];
                    $this->singletonServices[$service_id] = $dependantClosure->invokeArgs($resolvedDependencies);
                }

                return $this->singletonServices[$service_id];
            default:
                $dependantClosure = $this->factoryServices[$service_id];

                return $dependantClosure->invokeArgs($resolvedDependencies);
        }
    }
}
