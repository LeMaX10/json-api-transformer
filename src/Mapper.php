<?php
/**
 * Created by PhpStorm.
 * User: lemax
 * Date: 23.12.15
 * Time: 15:47
 */

namespace lemax10\JsonApiTransformer;


use Illuminate\Database\Eloquent\Collection;

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

    public function __construct($transformer)
    {
        $this->transformer = $transformer;
        $this->responseBody = new Collection();
    }

    public function toJsonObjectResponse($object)
    {
        if(!is_object($object))
            throw new \RuntimeException('Error loading response data');

        $responseBody = $this->response($object);
        $responseBody->put('jsonapi', '1.0');
        return response()->json($responseBody, 200);
    }

    public function toJsonPaginationResponse($result)
    {
        $responseBody = $this->response($result->getCollection());
        $this->createPagination($result);
        $responseBody->put('jsonapi', '1.0');
        return response()->json($responseBody, 200);
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
        foreach($this->transformer->getIdProperties() as $idAttr) {
            if(!isset($model->{$idAttr}))
                continue;

            $item->put($idAttr, $model->{$idAttr});
        }

        return $this;
    }

    protected function getFieldsModel($model, &$item) {

        foreach($this->transformer->getHideProperties() as $fieldHidden) {
            if(!isset($model->{$fieldHidden}))
                continue;

            unset($model->{$fieldHidden});
        }
        foreach($this->transformer->getAliasedProperties() as $field => $alias) {
            if(!isset($model->{$field}))
                continue;

            $model->{$alias} = $model->{$field};
            unset($model->{$field});
        }

        if($filter = \Request::input('filter.'. $this->transformer->getAlias(), false))
            $model = collect($model)->only(explode(',', $filter));

        $item->put(self::ATTR_ATTRIBUTES, $model);
        return $this;
    }

    protected function getLinksModel($model, &$item)
    {
        $links = [];
        foreach($this->transformer->getUrls() as $type => $param) {
            $routeParams = ['id' => ''];
            if(isset($param['as_id']) && isset($model->{$param['as_id']}))
                $routeParams['id'] = $model->{$param['as_id']};

            $method = isset($param['type']) ? $param['type'] : self::GET_METHOD;
            $links[$type] = [
                'type' => strtolower($method),
                'url' => route($param['name'], $routeParams)
            ];
        }

        if(count($links))
            $item->put(self::ATTR_LINKS, $links);

        return $this;
    }

    protected function getRelationsShip($model, &$item)
    {
        $result = [];
        foreach($this->transformer->getRelationships() as $modelMethod => $relation) {
            $result[self::ATTR_RELATIONSHIP][$modelMethod] = [
                self::ATTR_DATA => [
                    'type' => (new $relation['transformer'])->getAlias()
                ]
            ];

            unset($relation['transformer']);
            foreach($relation as $field => $modelAttribute) {
                if(!isset($this->model->{$modelAttribute}))
                    continue;

                $result[self::ATTR_RELATIONSHIP][$modelMethod][self::ATTR_DATA][$field] = $model->{$modelAttribute};
            }
        }

        if(count($result))
            $item->put(self::ATTR_RELATIONSHIP, $result);

        return $this;
    }

    protected function getIncluded($model) {
        if(!$included = \Request::get('include', false))
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

        $abort = false;
        $collection = collect($this->transformer->getMeta())->transform(function($item, $key) use(&$abort, &$model) {
            if(!method_exists($model, "get".ucfirst($item)) || !count($return = $model->{"get".ucfirst($item)}())){
                $abort = true;
                return null;
            }

            return $return;
        });

        if($abort)
            return $this;

        $item->put(self::ATTR_META, $collection);
        return $this;
    }
}