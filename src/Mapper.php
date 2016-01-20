<?php
/**
 * Created by PhpStorm.
 * User: lemax
 * Date: 23.12.15
 * Time: 15:47
 */

namespace lemax10\JsonApiTransformer;


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

    public function toJsonObjectResponse($object)
    {
        return (new ObjectResponse($this->transformer, $object))->response();
    }

    public function toJsonPaginationResponse($result)
    {
        $this->paginate = true;
        return (new ObjectPaginationResponse($this->transformer, $result))->response();
    }

    protected function createPagination($result)
    {
        $this->responseBody->put(self::ATTR_META, ['total-pages' => $result->total()]);
        $result->setPageName('page[number]')->appends('page[size]', $result->perPage());
        $links = [
            'self' => $result->url($result->currentPage()),
            'first' => $result->url(1)
        ];
        if($result->currentPage() > 1 && $result->currentPage() <= $result->lastPage())
            $links['prev'] = $result->url($result->currentPage() - 1);

        if($result->hasMorePages())
            $links['next'] = $result->nextPageUrl();

        $links['last'] = $result->url($result->lastPage());

        $this->responseBody->put(self::ATTR_LINKS, $links);
    }

    public function response($model = false)
    {
        if($model !== false)
            $this->model = $model;

        if($this->model instanceof Collection)
            $this->responseBody->put(self::ATTR_DATA, $this->collection());
        else
            $this->responseBody->put(self::ATTR_DATA, [$this->modelMapping()]);

        $this->getIncluded($this->model);
        return $this->responseBody;
    }

    protected function collection()
    {
        return $this->model->transform(function ($item, $key) {
            return $this->modelMapping($item);
        });
    }

    protected function modelMapping($model = false)
    {
        if($model === false)
            $model = $this->model;

        $item = new Collection([self::ATTR_TYPE => $this->transformer->getAlias()]);
        $this->getIdProperty($model, $item)
             ->getFieldsModel($model, $item)
             ->getLinksModel($model, $item)
             ->getRelationsShip($model, $item)
             ->getMeta($model, $item);

        return $item;
    }

    protected function getIdProperty($model, &$item)
    {
        $model = collect($model);
        foreach($this->transformer->getIdProperties() as $idAttr) {
            if(empty($model->get($idAttr)))
                continue;

            $item->put($idAttr, $model->get($idAttr));
        }

        unset($model);
        return $this;
    }

    protected function getFieldsModel($model, &$item) {
        $model = collect($model);
        $model = $model->except($this->transformer->getHideProperties());
        foreach($this->transformer->getAliasedProperties() as $field => $alias) {
            if(empty($value = $model->get($field)))
                continue;

            $model->put($alias, $value)->pull($field);
        }

        if($filter = \Request::input('filter.'. $this->transformer->getAlias(), false))
            $model = $model->only(explode(',', $filter));

        if(count($this->transformer->getRelationships())) {
            $relationships = $this->transformer->getRelationships();
            $model = $model->transform(function ($value, $key) use (&$relationships) {
                if (!in_array($key, array_keys($relationships)))
                    return $value;

                $transformer = $relationships[$key];
                return (new Mapper(new $transformer['transformer']))->response($value);
            });
        }

        $item->put(self::ATTR_ATTRIBUTES, $model);
        unset($model);
        return $this;
    }

    protected function getLinksModel($model, &$item)
    {
        $links = [];
        foreach($this->transformer->getUrls() as $type => $param) {
            $routeParams = new Collection();
            $routeParam  = isset($param['routeParam']) ? $param['routeParam'] : 'id';
            if(isset($param['as_' . $routeParam])) {
                $modelValue = is_array($model) ? $model[$param['as_' . $routeParam]] : $model->{$param['as_' . $routeParam]};
                $routeParams->put($routeParam, $modelValue);
            }

            $method = isset($param['type']) ? $param['type'] : self::GET_METHOD;
            $links[$type] = [
                'type' => strtolower($method),
                'url' => route($param['name'], $routeParams->toArray())
            ];
        }

        if(count($links))
            $item->put(self::ATTR_LINKS, $links);

        unset($model);
        return $this;
    }

    protected function getRelationsShip($model, &$item)
    {

        $result = [];
        foreach($this->transformer->getRelationships() as $modelMethod => $relation) {
            $result[$modelMethod] = [
                self::ATTR_DATA => [
                    'type' => (new $relation['transformer'])->getAlias()
                ]
            ];

            unset($relation['transformer']);
            foreach($relation as $field => $modelAttribute) {
                if(!isset($this->model->{$modelAttribute}))
                    continue;

                $result[$modelMethod][self::ATTR_DATA][$field] = $model->{$modelAttribute};
            }
        }

        if(count($result))
            $item->put(self::ATTR_RELATIONSHIP, $result);

        unset($result);
        return $this;
    }

    protected function getIncluded($model) {
        if($this->paginate || !$included = \Request::get('include', false))
            return false;

        if(!in_array($included, array_keys($this->transformer->getRelationships())))
            return false;

        $transformer = $this->transformer->getRelationships()[$included]['transformer'];
        $this->responseBody->put(self::ATTR_INCLUDES, (new Mapper(new $transformer))->response($model->{$included}()->get()));
        return $this;
    }

    protected function getMeta($model, &$item)
    {
        if(!count($this->transformer->getMeta()))
            return $this;

        $meta  = [];
        foreach($this->transformer->getMeta() as $metaItem)
        {
            if(!method_exists($model, "get" . ucfirst($metaItem)) || !count($return = $model->{"get".ucfirst($metaItem)}()))
                break;

            $meta = array_merge($meta, $return);
        }

        if(count($meta))
            $item->put(self::ATTR_META, $meta);

        unset($meta);
        return $this;
    }

    public static function customObject($type, $attributes)
    {
        return [
            self::ATTR_TYPE => $type,
            self::ATTR_ATTRIBUTES => $attributes
        ];
    }
}