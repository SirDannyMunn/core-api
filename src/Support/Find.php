<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Exception;
use Error;

class Find
{
    public static function httpResourceForModel(Model $model, ?int $version = 1)
    {
        $resourceNamespace = null;
        $resourceNS = $baseNamespace = "\\Fleetbase\\Http\\Resources\\";
        $modelName = Utils::classBasename($model);

        if (isset($model->httpResource)) {
            $resourceNamespace = $model->httpResource;
        }

        if (isset($model->resource)) {
            $resourceNamespace = $model->resource;
        }

        if ($resourceNamespace === null) {
            $internal = Http::isInternalRequest();

            if ($internal) {
                $baseNamespace .= "Internal\\";
            }

            $resourceNamespace = $baseNamespace . "v{$version}\\" . $modelName;

            // if internal request but no internal resource has been declared
            // fallback to the public resource
            if (!class_exists($resourceNamespace)) {
                $resourceNamespace = str_replace("Internal\\", '', $resourceNamespace);
            }
        }

        try {
            if (!class_exists($resourceNamespace)) {
                throw new Exception('Missing resource');
            }
        } catch (Error | Exception $e) {
            $resourceNamespace = $resourceNS . "FleetbaseResource";
        }

        return $resourceNamespace;
    }

    public static function httpRequestForModel(Model $model, ?int $version = 1)
    {
        $requestNamespace = null;
        $requestNS = $baseNamespace = "\\Fleetbase\\Http\\Requests\\";
        $modelName = Utils::classBasename($model);

        if (isset($model->httpRequest)) {
            $requestNamespace = $model->httpRequest;
        }

        if (isset($model->request)) {
            $requestNamespace = $model->request;
        }

        if ($requestNamespace === null) {
            $requestNamespace = $requestNS . "\\" . Str::studly(ucfirst(Http::action()) . ucfirst($modelName) . 'Request');
        }

        if (!class_exists($requestNamespace)) {
            $internal = Http::isInternalRequest();

            if ($internal) {
                $baseNamespace .= "Internal\\";
            }

            $requestNamespace = $baseNamespace . "v{$version}\\" . $modelName;
        }

        try {
            if (!class_exists($requestNamespace)) {
                throw new Exception('Missing resource');
            }
        } catch (Error | Exception $e) {
            $requestNamespace = $requestNS . "FleetbaseRequest";
        }

        return $requestNamespace;
    }

    public static function httpFilterForModel(Model $model)
    {
        $filterNamespace = null;
        $filterNs = "\\Fleetbase\\Http\\Filter\\";
        $modelName = Utils::classBasename($model);

        if (isset($model->httpFilter)) {
            $filterNamespace = $model->httpFilter;
        }

        if (isset($model->filter)) {
            $filterNamespace = $model->filter;
        }

        if ($filterNamespace === null) {
            $filterNamespace = $filterNs . Str::studly(ucfirst($modelName) . 'Filter');
        }

        if (class_exists($filterNamespace)) {
            return $filterNamespace;
        }

        return null;
    }
}
