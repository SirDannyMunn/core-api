<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionException;
use Exception;

class Resolve
{
    public static function httpResourceForModel($model, ?int $version = 1)
    {
        if (is_string($model) && class_exists($model)) {
            $model = static::instance($model);
        }

        if (!$model instanceof Model) {
            throw new Exception('Invalid model to resolve resource for!');
        }

        $resourceNamespace = Find::httpResourceForModel($model, $version);

        return new $resourceNamespace($model);
    }

    public static function httpRequestForModel($model, ?int $version = 1)
    {
        if (is_string($model) && class_exists($model)) {
            $model = static::instance($model);
        }

        if (!$model instanceof Model) {
            throw new Exception('Invalid model to resolve request for!');
        }

        $requestNamespace = Find::httpRequestForModel($model, $version);

        return new $requestNamespace();
    }

    public static function httpFilterForModel(Model $model, Request $request)
    {
        $filterNamespace = Find::httpFilterForModel($model);

        if ($filterNamespace) {
            return new $filterNamespace($request);
        }

        return null;
    }

    public static function resourceForMorph($type, $id)
    {
        if (empty($type) || empty($id)) {
            return null;
        }

        if (class_exists($type)) {
            $instance = static::instance($type);

            if ($instance instanceof Model) {
                $instance = $instance->where($instance->getQualifiedKeyName(), $id)->first();
            }
        }

        if ($instance) {
            $resource = Find::httpResourceForModel($instance);

            return new $resource($instance);
        }

        return null;
    }

    /**
     * Creates a new instance from a ReflectionClass.
     *
     * @param string $class
     * @return mixed
     */
    public static function instance($class, $args = [])
    {
        if (is_object($class) === false && is_string($class) === false) {
            return null;
        }

        $instance = null;

        try {
            $instance = (new ReflectionClass($class))->newInstance(...$args);
        } catch (ReflectionException $e) {
            $instance = app($class);
        }

        return $instance;
    }
}
