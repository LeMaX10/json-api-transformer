<?php
namespace lemax10\JsonApiTransformer\Response;

use Illuminate\Support\Collection;
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
		$this->transformer = $transformer;
		if($model) {
			$this->model = $model;
			$this->initIncludes();
		}
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
			if(!isset($this->includes[$type]['transformer']) && isset($typeOpt['autowired']) && $typeOpt['autowired'] === true)
				$this->includes[$type]['transformer'] = $typeOpt['tranformer'];
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
			$transformModel = $this->parseMeta($transformModel);

		return $transformModel;
	}

	protected function getIdentifier($model)
	{
		return 1;
	}

	protected function getAttributes($model)
	{
		$model = collect($model->getAttributes());
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

		return $model;
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
				if(in_array($type, ['name']) || strpos($type, 'as_') === false || empty($model->get($value))) continue;
				$routeParam[ltrim($type, 'as_')] = $model->get($value);
			}

			$links[$link] = [
				'type' => (isset($linkParam['type']) ? $linkParam['type'] : Mapper::GET_METHOD),
				'url'  => route($linkParam['name'], $routeParam)
			];
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
			if(!isset($param['transformer'])) continue;
			$selfTransformer = new $param['transformer'];
			$relationShips[$type] = [
				'data' => [
					'type' => $selfTransformer->getAlias(),
					'id'   => (int) $this->model->{$type}->first()->id
				]
			];

			$current = (new self($selfTransformer, $this->model->{$type}))->getRelationsShips();

			if(isset($param['relationships']) && count($param['relationships'])) {
				$current[Mapper::ATTR_RELATIONSHIP] = [];
				foreach ($param['relationships'] as $childType => $childParam) {
					if (!isset($this->model->{$type}->{$childType})) continue;
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

							$included[] = (new self($relTransformer, $modelChild))->getRelationsShips();
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

					$included[] = (new self($relTransformer, $this->model->{$type}->{$childType}))->getRelationsShips();
				}
			}

			$included[] = $current;
		}

		if(count($included)) {
			$model->put(Mapper::ATTR_RELATIONSHIP, $relationShips);
			$this->setInclude($included);
		}
		return $model;
	}

	public function getRelationsShips()
	{
		if($this->model instanceof \Illuminate\Database\Eloquent\Collection)
			return $this->transformCollection($this->model);
		else
			return $this->transformModel($this->model);
	}

	protected function parseMeta($model)
	{
		$meta = [];
		foreach($this->transformer->getMeta() as $method)
		{
			if(!method_exists(get_class($this->model), 'get' . ucfirst($method))) continue;
			$meta = array_merge($meta, $this->model->{'get' . ucfirst($method)}());
		}

		if(count($meta))
			$model->put(Mapper::ATTR_META, $meta);

		return $model;
	}
}