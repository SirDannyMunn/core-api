<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernelException\NotFoundHttpException;
use Exception;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Support\Resolve;
use Illuminate\Database\QueryException;

trait HasApiControllerBehavior
{
    /**
     * The target eloquent data model.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $model;

    /**
     * The target eloquent data model class name.
     *
     * @var string
     */
    public $modelClassName;

    /**
     * The target resource name pluralized.
     *
     * @var string
     */
    public $resourcePluralName;

    /**
     * The target resource name singularized.
     *
     * @var string
     */
    public $resourceSingularlName;

    /**
     * The target API Resource.
     * 
     * @var \Fleetbase\Http\Resources\FleetbaseResource
     */
    public $resource;

    /**
     * The current request.
     *
     * @var \Illuminate\Http\Request
     */
    public $request;

    private function actionFromHttpVerb(?string $verb = null)
    {
        $verb = $verb ?? $_SERVER['REQUEST_METHOD'];
        $action = Str::lower($verb);

        switch ($verb) {
            case 'POST':
                $action = 'create';
                break;

            case 'GET':
                $action = 'query';
                break;

            case 'PUT':
            case 'PATCH':
                $action = 'update';
                break;

            case 'DELETE':
                $action = 'delete';
                break;
        }

        return $action;
    }

    /**
     * Set the model instance to use
     *
     * @param \Illuminate\Database\Eloquent\Model $model - The Model Instance
     */
    public function setApiModel(?Model $model = null, string $namespace = '\\Fleetbase\\Models\\')
    {
        $this->modelClassName = $modelName = Utils::getModelClassName($model ?? $this->resource, $namespace);
        $this->model = $model = Resolve::instance($modelName);
        $this->resource = $this->getApiResourceForModel($model);
        $this->request = $this->getApiRequestForModel($model);
        $this->resourcePluralName = Str::plural($model->getTable());
        $this->resourceSingularlName = Str::singular($model->getTable());
    }

    /**
     * Set the Resource object to use
     *
     * @param Resource|string $resources
     */
    public function setApiResource($resource)
    {
        if (!$this->resource) {
            $this->resource = (is_object($resource) ? get_class($resource) : $resource) ?? $this->getApiResourceForModel($this->model);
        }
    }

    /**
     * Set the FormRequest object to use.
     *
     * @param FormRequest|string $request
     */
    public function setApiFormRequest($request)
    {
        $this->request = is_object($request) ? get_class($request) : $request;
    }

    /**
     * Resolves the api resource for this model
     *
     * @param \Fleetbase\Models\Model $model
     * @return \Fleetbase\Http\Resources\FleetbaseResource
     */
    public function getApiResourceForModel(Model $model)
    {
        $resource = $this->resource;

        if (!$resource || !Str::startsWith($resource, '\\')) {
            $resource = Resolve::httpResourceForModel($model);
        }

        return $resource;
    }

    /**
     * Resolves the form request for this model.
     *
     * @param \Fleetbase\Models\Model $model
     * @return \Fleetbase\Http\Requests\FleetbaseRequest
     */
    public function getApiRequestForModel(Model $model)
    {
        $request = $this->request;

        if (!$request) {
            $request = Resolve::httpRequestForModel($this->model);
        }

        return $request;
    }

    /**
     * Resolves the resource form request and validates.
     *
     * @throws \Fleetbase\Exceptions\FleetbaseRequestValidationException
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    public function validateRequest(Request $request)
    {
        if (class_exists($this->request)) {
            $formRequest = new $this->request($request->all());
            $validator = Validator::make($request->all(), $formRequest->rules(), $formRequest->messages());

            if ($validator->fails()) {
                throw new FleetbaseRequestValidationException($validator->errors());
            }
        }
    }

    /**
     * Get All
     *
     * Returns a list of items in this resource and allows filtering the data based on fields in the database
     *
     * Options for searching / filtering
     * - By field name: e.g. `?name=John` - Specific search
     * - By field name with `LIKE` operator: e.g. `?name_like=John` - Fuzzy search
     * - By field name with `!=` operator: e.g. `?age_not=5`
     * - By field name with `>` or `<` operator: e.g. `?age_gt=5` or `?age_lt=10`
     * - By field name with `>=` or `<=` operator: e.g. `?age_gte=5` or `?age_lte=10`
     * - By field name with `IN` or `NOT IN` operator: e.g. `?id_in=1,3,5` or `?id_notIn=2,4`
     * - By field name with `NULL` or `NOT NULL` operator: e.g. `?email_isNull` or `?email_isNotNull`
     *
     * @queryParam limit Total items to return e.g. `?limit=15`. Example: 3
     * @queryParam page Page of items to return e.g. `?page=1`. Example: 1
     * @queryParam sort Sorting options e.g. `?sort=field1:asc,field2:asc` OR `?sort=latest/oldest` OR `?sort=-created,created`. Example: latest
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     * @queryParam fieldName Pass any field and value to filter results e.g. `name=John&email=any@aol.com`. No-example
     *
     * @authenticated
     * @return \Illuminate\Http\Response
     */
    public function queryRecord(Request $request)
    {
        $data = $this->model->queryFromRequest($request);

        if (Http::isInternalRequest($request)) {
            $this->resource::wrap($this->resourcePluralName);
            return $this->resource::collection($data);
        }

        return $this->resource::collection($data);
    }

    /**
     * View Resource
     *
     * Returns information about a specific record in this resource. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     * @urlParam id integer required The id of the resource to view
     *
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function findRecord(Request $request, $id)
    {
        $record = $this->model->getById($id, $request);

        if ($record) {
            return [$this->resourceSingularlName => new $this->resource($record)];
        }

        return response()->error($this->resourceSingularlName . ' not found', 404);
    }

    /**
     * Create Resource
     *
     * Create a new record of this resource in the database. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam with|expand Contain data from related model e.g. `?with=relation1,relation2`. No-example
     *
     * @response 400 {
     *  "status": "error",
     *  "message": [
     *     "validation error message"
     *  ]
     * }
     *
     * @response 500 {
     *  "status": "error",
     *  "message": "Details of error message"
     * }
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        try {
            $this->validateRequest($request);
            $record = $this->model->createRecordFromRequest($request); 

            return new $this->resource($record);
        } catch (Exception $e) {
            return response()->error($e->getMessage());
        } catch (QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Update Resource
     *
     * Updates the data of the record with the specified `id`. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     *
     * @response 400 {
     *  "status": "error",
     *  "message": [
     *     "validation error messages"
     *  ]
     * }
     *
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @response 500 {
     *  "status": "error",
     *  "message": "Details of error message"
     * }
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request, string $id)
    {
        try {
            $this->validateRequest($request);
            $record = $this->model->updateRecordFromRequest($request);

            return new $this->resource($record);
        } catch (Exception $e) {
            return response()->error($e->getMessage());
        } catch (QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (NotFoundHttpException $e) {
            return response()->error(($this->resourceSingularlName ?? 'Resource') . ' not found');
        }
    }

    /**
     * Delete Resource
     *
     * Deletes the record with the specified `id`
     *
     * @authenticated
     *
     * @response {
     *  "status": "success",
     *  "message": "Resource deleted",
     *  "data": {
     *     "id": 1
     *  }
     * }
     *
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $dataModel = $this->model->wherePublicId($id);

        if ($dataModel) {
            $dataModel->delete();

            return response()->json(
                [
                    'status' => 'success',
                    'message' => 'Resource deleted',
                    'data' => $dataModel,
                ]
            );
        }

        return response()->json(
            [
                'status' => 'failed',
                'message' => 'Resource not found',
            ],
            404
        );
    }

    /**
     * Search Resources
     *
     * Allows searching for data in this resource using multiple options.
     *
     * Options for searching
     * - By field name: e.g. `?name=John` - Specific search
     * - By field name with `LIKE` operator: e.g. `?name_like=John` - Fuzzy search
     * - By field name with `!=` operator: e.g. `?age_not=5`
     * - By field name with `>` or `<` operator: e.g. `?age_gt=5` or `?age_lt=10`
     * - By field name with `>=` or `<=` operator: e.g. `?age_gte=5` or `?age_lte=10`
     * - By field name with `IN` or `NOT IN` operator: e.g. `?id_in=1,3,5` or `?id_notIn=2,4`
     * - By field name with `NULL` or `NOT NULL` operator: e.g. `?email_isNull` or `?email_isNotNull`
     *
     * @queryParam limit Total items to return e.g. `?limit=15`. Example: 3
     * @queryParam page Page of items to return e.g. `?page=1`. Example: 1
     * @queryParam sort Sorting options e.g. `?sort=field1:asc,field2:asc` OR `?sort=latest/oldest`. Example: latest
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     * @queryParam fieldName Pass any field and value to search by e.g. `name=John&email=any@aol.com`. Search logic may use LIKE or `=` depending on field
     *
     * @authenticated
     */
    public function search(Request $request)
    {
        $results = $this->model->search($request);

        return $this->resource::collection($results);
    }

    /**
     * Count Resources
     *
     * Returns a simple count of data in this resource
     *
     * @queryParam fieldName Pass any field and value to search by e.g. `name=John&email=any@aol.com`. Search logic may use LIKE or `=` depending on field. No-example
     *
     * @authenticated
     */
    public function count(Request $request)
    {
        $results = $this->model->count($request);

        return response()->json(['count' => $results]);
    }
}
