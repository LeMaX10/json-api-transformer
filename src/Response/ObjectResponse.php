<?php
namespace lemax10\JsonApiTransformer\Response;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use lemax10\JsonApiTransformer\Mapper;
use Request;

class ObjectResponse
{
	protected $includes = [];
	protected $loadIncludes = [];
	protected $relation = [];
	protected $relationships = [];
	protected $responseBody;
	protected $transformer;
	protected $model;
	protected $timer = [];
	public function __construct($transformer, $object)
	{
		$this->timer['full'] = microtime(true);
		$this->responseBody = new Collection(['jsonapi'   => '1.0']);
		$this->setTransformer($transformer);
		if($object) {
			$this->model = $object;
			$this->initIncludes();
		}
	}

	protected function setTransformer($transformer)
	{
		$this->transformer = is_object($transformer) ? $transformer : new $transformer;
		return $this;
	}

	protected function newInstance($transformer, $model)
	{
		if(!is_object($transformer))
			$transformer = new $transformer;

		return new self($transformer, $model);
	}

	protected function initIncludes()
	{
		$this->relation = $this->transformer->getRelationships();
		if(count($this->getLoadingIncluded()))
			$this->model->load($this->getLoadingIncluded());
	}

	protected function getLoadingIncluded()
	{
		$relations = [];
		if(Request::has('includes'))
			$relations = array_merge($relations, explode(',', Request::input('includes')));

		foreach($this->relation as $key => $relation) {
			if(isset($relation['autowired']) && $relation['autowired'] === true)
				$relations[] = $key;
		}

		return $relations;
	}

	public function response()
	{
		$this->timer['response'] = microtime(true);
		return response()->json($this->getResponse($this->model));
	}

	public function getResponse()
	{
		$this->timer['model'] = microtime(true);
		if($this->model instanceof \Illuminate\Database\Eloquent\Collection)
			$this->setData($this->transformCollection($this->model));
		else
			$this->setData([$this->transformModel($this->model)]);

		if(!empty($this->responseBody->get(Mapper::ATTR_INCLUDES)))
			$this->responseBody->put(Mapper::ATTR_INCLUDES, array_values($this->responseBody->get(Mapper::ATTR_INCLUDES)));

		$times = [
			'fullTransform' => round((microtime(true) - $this->timer['full']) * 1000) . 'ms',
		];

		if(isset($this->timer['response']))
			$times['responseTransform'] =  round((microtime(true) - $this->timer['response']) * 1000) . 'ms';

		if(isset($this->timer['relation']))
			$times['relationTransform'] = round((microtime(true) - $this->timer['relation']) * 1000) . 'ms';

		if(isset($this->time['model']))
			$times['modelTransform']    = round((microtime(true) - $this->timer['model']) * 1000) . 'ms';

		$this->responseBody->put('DEBUG', $times);

		return $this->responseBody;
	}

	public function setData($data)
	{
		$this->responseBody->put(Mapper::ATTR_DATA, $data);
	}

	public function getData()
	{
		return empty($this->responseBody->get(Mapper::ATTR_DATA)) ? [] : $this->responseBody->get(Mapper::ATTR_DATA);
	}

	protected function transformCollection($modelCollection)
	{
		return $modelCollection->transform(function($item)
		{
			return $this->transformModel($item);
		});
	}

	protected function transformModel($model)
	{
		$collectModel = collect($model);
		$responseModel = new Collection([
			Mapper::ATTR_TYPE => $this->getType(),
			Mapper::ATTR_IDENTIFIER => $this->getIdentifier($collectModel),
			Mapper::ATTR_ATTRIBUTES => $this->getAttributes($collectModel)
		]);

		if(count($model->getRelations()))
			$responseModel->put(Mapper::ATTR_RELATIONSHIP, $this->parseRelations($model));

		if(count($this->transformer->getUrls()))
			$responseModel = $this->parseUrls($collectModel);

		if(count($this->transformer->getMeta()))
			$this->parseMeta($responseModel);

		return $responseModel;
	}

	protected function getType()
	{
		return $this->transformer->getAlias();
	}

	protected function getIdentifier($collectModel)
	{
		return $collectModel->get('id');
	}

	protected function getAttributes($collectModel)
	{
		if(Request::has('filter.' . $this->transformer->getAlias()))
			return $collectModel->only(explode(',', Request::input('filter.' . $this->transformer->getAlias())));

		if(count($this->transformer->getHideProperties()))
			$collectModel = $collectModel->except($this->transformer->getHideProperties());

		if(count($this->transformer->getAliasedProperties())) {
			foreach($this->transformer->getAliasedProperties() as $modelField => $aliasField) {
				if(empty($collectModel->get($modelField)))
					continue;

				$collectModel->put($aliasField, $collectModel->get($modelField))->pull($modelField);
			}
		}

		return $this->shakeAttributes($collectModel);
	}

	protected function parseRelations($model)
	{
		$this->timer['relation'] = microtime(true);
		$return = [];
		$includes = [];
		$originalTransformer = $this->transformer;

		foreach($model->getRelations() as $key => $relation)
		{
			if($relation instanceof Collection) {
				$collection = $relation->each(function($item) use(&$return, $key, &$includes) {
					$transformer = $item::getTransformer();

					$return[$key]['data'][] = [
						'type' => (new $transformer)->getAlias(),
						'id' => $item->id
					];

					$includes[(new $transformer)->getAlias().$item->id] = $this->setTransformer($transformer)->transformModel($item);
				});

				continue;
			}

			$transformer = $relation::getTransformer();
			$return[$key]['data'] = [
				'type' => (new $transformer)->getAlias(),
				'id' => $relation->id
			];

			$includes[(new $transformer)->getAlias().$relation->id] = $this->setTransformer($transformer)->transformModel($relation);
		}
		$this->setTransformer($originalTransformer);

		if(!empty($this->responseBody->get(Mapper::ATTR_INCLUDES)))
			$includes = array_merge($this->responseBody->get(Mapper::ATTR_INCLUDES), $includes);

		$this->responseBody->put(Mapper::ATTR_INCLUDES, $includes);
		return $return;
	}

	protected function parseUrls($model)
	{
		$links = [];
		foreach($this->transformer->getUrls() as $link => $linkParam)
		{
			$routeParam = [];
			foreach($linkParam as $type => $value) {
				$attributes = empty($model->get('attributes')) ? $model : $model->get('attributes');
				if(in_array($type, ['name']) || Str::contains($type, 'as_') === false || empty($attributes->get($value))) continue;
				$routeParam[ltrim($type, 'as_')] = $attributes->get($value);
			}

			$links[$link] = route($linkParam['name'], $routeParam);
		}

		$model->put(Mapper::ATTR_LINKS, $links);
		unset($links);
		return $model;
	}

	protected function parseMeta($model)
	{
		$meta = [];
		foreach($this->transformer->getMeta() as $method)
		{
			if(!method_exists(get_class($this->model), 'get' . Str::ucfirst($method))) continue;
			$meta = array_merge($meta, $this->model->{'get' . Str::ucfirst($method)}());
		}

		if(count($meta)) {
			$this->responseBody->put(Mapper::ATTR_META, $meta);
			$model->put(Mapper::ATTR_META, $meta);
		}

		return $model;
	}

	protected function shakeAttributes($model)
	{
		foreach($model->toArray() as $key => $value) {
			if(Str::contains($key, "_") === false || Str::contains($key, "_id") !== false) continue;
			$model->put(Str::camel($key), $value)->pull($key);
		}

		return $model;
	}
}