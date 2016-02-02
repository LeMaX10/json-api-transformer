<?php
/**
 * Created by PhpStorm.
 * User: lemax
 * Date: 23.12.15
 * Time: 15:47
 */

namespace lemax10\JsonApiTransformer;


use App\Http\Requests\PaginationRequest;
use Illuminate\Database\Eloquent\Collection;
use lemax10\JsonApiTransformer\Response\ObjectPaginationResponse;
use lemax10\JsonApiTransformer\Response\ObjectResponse;

class Mapper
{
    const GET_METHOD    = 'GET';
    const POST_METHOD   = 'POST';
    const PUT_METHOD    = 'PUT';
    const DELETE_METHOD = 'DELETE';

    const ATTR_DATA = 'data';
    const ATTR_TYPE = 'type';
    const ATTR_META = 'meta';
    const ATTR_IDENTIFIER = 'id';
    const ATTR_ATTRIBUTES = 'attributes';
    const ATTR_RELATIONSHIP = 'relationships';
    const ATTR_LINKS    = 'links';
    const ATTR_INCLUDES = 'included';

    protected $responseBody;

    protected $transformer;
    protected $model;
    protected $paginate = false;
    protected $hide = [];

    protected $cast = false;

    public function __construct($transformer)
    {
        $this->transformer = new $transformer;
        $this->responseBody = new Collection();
    }

    public function toJsonArrayResponse($object)
    {
        $this->model = collect($object);
        $this->responseBody->put(self::ATTR_DATA, $this->collection());
        $this->responseBody->put(self::ATTR_META, ['items' => count($object)]);
        $this->responseBody->put('jsonapi', '1.0');
        return response()->json($this->responseBody, 200);
    }

    public function toJsonObjectResponse($object, $merge = false)
    {
        $objectBuilder = new ObjectResponse($this->transformer, $object, $merge);
        if($this->getCast() !== false)
            $objectBuilder->setCast($this->getCast());

        return $objectBuilder->response();
    }

    public function toJsonPaginationResponse($result, PaginationRequest $request, $merge = false)
    {
        $this->paginate = true;

        $objectBuilder = new ObjectPaginationResponse($this->transformer, $this->checkModel($result), $request, $merge);
        if($this->getCast() !== false)
            $objectBuilder->setCast($this->getCast());

        return $objectBuilder->response();
    }

    protected function checkModel($model)
    {
        return !is_object($model) ? new $model : $model;
    }

    public static function customObject($type, $attributes)
    {
        return [
            self::ATTR_TYPE => $type,
            self::ATTR_ATTRIBUTES => $attributes
        ];
    }

    public function setCast($cast)
    {
        $this->cast = $cast;
        return $this;
    }

    public function getCast()
    {
        return $this->cast;
    }
}