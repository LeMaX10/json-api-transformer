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

	public function __construct($transformer, $model = false)
	{

		$this->responseBody = new Collection(['jsonapi'   => '1.0']);
		$this->setTransformer($transformer);
		if($model) {
			$this->model = $model;
			$this->initIncludes();
		}
	}

	protected function setTransformer($transformer)
	{
		$this->transformer = $transformer;
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
		$this->queryIncludes();
		$this->autowiredIncludes();

		if(count($this->includes))
			$this->model->load($this->getLoadingIncluded());
	}

	protected function getLoadingIncluded()
	{
		$included = [];
		foreach($this->includes as $include => $paramInclude) {
			if(isset($paramInclude['relationships']))
				foreach(array_keys($paramInclude['relationships']) as $load)
					$included[] = join('.', [$include, $load]);
			else
				$included[] = $include;
		}

		return $included;
	}

	protected function autowiredIncludes()
	{
		if(count($this->relation)) foreach($this->relation as $type => $typeOpt)
		{
			if(!isset($this->includes[$type]['transformer']) && isset($typeOpt['autowired']) && $typeOpt['autowired'] === true) {
				if(Str::contains($type, '.') === false) {
					$this->includes[$type]['transformer'] = $typeOpt['transformer'];
					continue;
				}

				list($relinj, $reltype) = explode('.', $type);
				if(!isset($this->relation[$relinj]))
					continue;

				if(!isset($this->includes[$relinj]['transformer']) && isset($this->relation[$relinj]))
					$this->includes[$relinj]['transformer'] = $this->relation[$relinj]['transformer'];

				$reltypeInj = (new $this->relation[$relinj]['transformer'])->getRelationships()[$reltype];
				$this->includes[$relinj]['relationships'][$reltype]['transformer'] = $reltypeInj['transformer'];
			}
		}
	}

	protected function queryIncludes()
	{
		if(\Request::has('includes')) {
			$include = explode(',', \Request::input('includes'));
			foreach ($include as $rel)
			{
				if(strpos($rel, ".") !== false)
				{
					list($relinj, $reltype) = explode('.', $rel);
					if(!isset($this->relation[$relinj]))
						continue;

					if(!isset($this->includes[$relinj]['transformer']) && isset($this->relation[$relinj]))
						$this->includes[$relinj]['transformer'] = $this->relation[$relinj]['transformer'];

					$reltypeInj = (new $this->relation[$relinj]['transformer'])->getRelationships()[$reltype];
					$this->includes[$relinj]['relationships'][$reltype]['transformer'] = $reltypeInj['transformer'];
					continue;
				}

				if(!isset($this->relation[$rel]))
					continue;

				$this->includes[$rel]['transformer'] = $this->relation[$rel]['transformer'];
			}
		}
	}

	public function response()
	{
		return response()->json($this->getResponse($this->model));
	}

	public function getResponse()
	{
		if($this->model instanceof \Illuminate\Database\Eloquent\Collection)
			$this->setData($this->transformCollection($this->model));
		else
			$this->setData([$this->transformModel($this->model)]);

		return $this->responseBody;
	}

	public function transformCollection(\Illuminate\Database\Eloquent\Collection $collection)
	{
		return $collection->transform(function($value, $key) {
			return $this->transformModel($value);
		});
	}

	public function transformModel($model)
	{
		$transformModel = new Collection([
			Mapper::ATTR_TYPE => $this->transformer->getAlias(),
			Mapper::ATTR_IDENTIFIER => $this->getIdentifier($model),
			Mapper::ATTR_ATTRIBUTES => $this->getAttributes($model)
		]);

		if(count($this->transformer->getUrls()))
			$transformModel = $this->parseUrls($transformModel);

		if(count($this->includes))
			$transformModel = $this->parseIncludes($transformModel);

		if(count($this->transformer->getMeta()))
			$this->parseMeta($transformModel);

		return $transformModel;
	}

	protected function getIdentifier($model)
	{
		return is_array($model) ? $model['id'] : $model->id;
	}

	protected function getAttributes($model)
	{
		$model = collect($model);

		if(Request::has('filter.' . $this->transformer->getAlias()))
			return $model->only(explode(',', Request::input('filter.' . $this->transformer->getAlias())));

		if(count($this->transformer->getHideProperties()))
			$model = $model->except($this->transformer->getHideProperties());

		if(count($this->transformer->getAliasedProperties())) {
			foreach($this->transformer->getAliasedProperties() as $modelField => $aliasField) {
				if(empty($model->get($modelField)))
					continue;

				$model->put($aliasField, $model->get($modelField))->pull($modelField);
			}
		}

		return $this->shakeAttributes($model);
	}

	public function addData($data)
	{
		$this->responseBody->get(Mapper::ATTR_DATA)->push($data);
		return $this;
	}

	public function setData($data)
	{
		$this->responseBody->put(Mapper::ATTR_DATA, $data);
		return $this;
	}

	public function setInclude($include)
	{
		if(!empty($this->responseBody->get(Mapper::ATTR_INCLUDES)))
			$include = array_merge($include, array_diff($this->responseBody->get(Mapper::ATTR_INCLUDES), $include));

		$this->responseBody->put(Mapper::ATTR_INCLUDES, $include);
		return $this;
	}

	public function addInclude($include)
	{
		$this->responseBody->get(Mapper::ATTR_INCLUDES)->push($include);
		return $this;
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

	protected function parseIncludes($model)
	{
		$included = [];
		$relationShips = [];
		foreach($this->includes as $type => $param)
		{
			$selfTransformer = new $param['transformer'];
			$relationShips[$type] = [
				'data' => [
					'type' => $selfTransformer->getAlias(),
					'id'   => (int) $this->model->{$type}->id
				]
			];

			$current = (new self($selfTransformer, $this->model->{$type}))->getRelationsShips();

			if(isset($param['relationships']) && count($param['relationships'])) {
				$current[Mapper::ATTR_RELATIONSHIP] = [];
				foreach ($param['relationships'] as $childType => $childParam) {
					$relTransformer = new $childParam['transformer'];

					if ($this->model->{$type}->{$childType} instanceof Illuminate\Support\Collection) {
						$current[Mapper::ATTR_RELATIONSHIP][$childType] = [];
						foreach ($this->model->{$type}->{$childType} as $modelChild) {
							$current[Mapper::ATTR_RELATIONSHIP][$childType][] = [
								'data' => [
									'type' => $relTransformer->getAlias(),
									'id' => $modelChild->id
								]
							];

							$included[$childParam['transformer'].':'. $modelChild->id] = $this->newInstance($relTransformer, $modelChild)->getRelationsShips();
						}
						continue;
					}

					$current[Mapper::ATTR_RELATIONSHIP] = [
						$childType => [
							'data' => [
								'type' => $relTransformer->getAlias(),
								'id' => (int) $this->model->{$type}->{$childType}->id
							]
						]
					];

					$included[$childParam['transformer'] .':'. $this->model->{$type}->{$childType}->id] = $this->newInstance($relTransformer, $this->model->{$type}->{$childType})->getRelationsShips();
				}
			}

			$included[$param['transformer'] .':'. $this->model->{$type}->id] = $current;
		}

		if(count($included)) {
			$model->put(Mapper::ATTR_RELATIONSHIP, $relationShips);
			$this->setInclude(array_values($included));
		}
		return $model;
	}

	public function getRelationsShips()
	{
		if($this->model instanceof \Illuminate\Database\Eloquent\Collection)
			return $this->transformCollection($this->model);
		elseif(is_array($this->model))
			return $this->transformCollection(collect($this->model));
		else
			return $this->transformModel($this->model);
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